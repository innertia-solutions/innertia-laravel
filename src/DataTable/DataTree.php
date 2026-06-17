<?php

namespace Innertia\DataTable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DataTree — server-side tree DataTable (PostgreSQL).
 *
 * Diferencias con DataTable:
 *  - Sin paginación page/perPage. Lazy load por subárbol via `expand=<id>`.
 *  - Respuesta anidada: cada nodo trae `depth`, `has_children`, `children` (cuando aplica).
 *  - CTE recursivo PostgreSQL para fetch en una query, sin N+1.
 *  - `has_children` resuelto en batch con un único GROUP BY.
 *
 * Uso:
 *
 *   $tree = DataTree::create('sedes', Sede::class)
 *       ->columns(['name', 'address', 'description'])
 *       ->parentColumn('parent_id')
 *       ->maxDepth(3)
 *       ->prepareQuery(fn ($q) => $q->where('active', true));
 *
 *   return $tree->render($request);
 */
class DataTree
{
    private string $name;

    private string $modelClass;

    /** Columnas que retorna cada nodo (sin contar id/parent que se incluyen automáticamente). */
    private array $columns = [];

    private array $hiddenColumns = [];

    /** Columna que identifica al padre en la misma tabla. */
    private string $parentColumn = 'parent_id';

    /** Niveles eager-loaded en la respuesta inicial (1 = solo roots, 2 = roots + hijos, ...). */
    private int $maxDepth = 3;

    private string $orderColumn = 'name';

    private string $orderDirection = 'asc';

    /** Columna a usar para búsqueda (LOWER LIKE). */
    private string $searchColumn = 'name';

    /** Expresión SQL cruda para búsqueda (concatenación/calculada). Si se setea, tiene prioridad sobre searchColumn. */
    private ?string $searchExpression = null;

    private bool $addIdColumn = true;

    private bool $debug = false;

    private bool $enableIncludeTrashed = false;

    /** @var callable|null Aplica filtros adicionales a la query de roots/expand. */
    private $prepareQueryMethod = null;

    /** Modelos extra cuyos canales realtime se declaran además del modelo principal. */
    private array $realtimeListen = [];

    private bool $realtimeEnabled = true;

    public function __construct(string $name, string $modelClass)
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            throw new \InvalidArgumentException("DataTree: {$modelClass} must extend Eloquent Model.");
        }
        $this->name = $name;
        $this->modelClass = $modelClass;
    }

    public static function create(string $name, string $modelClass): self
    {
        return new self($name, $modelClass);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Configuration API
    // ─────────────────────────────────────────────────────────────────────────

    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    public function hiddenColumns(array $hiddenColumns): self
    {
        $this->hiddenColumns = $hiddenColumns;

        return $this;
    }

    public function parentColumn(string $column): self
    {
        $this->parentColumn = $column;

        return $this;
    }

    public function maxDepth(int $depth): self
    {
        if ($depth < 1) {
            throw new \InvalidArgumentException('DataTree: maxDepth must be >= 1.');
        }
        $this->maxDepth = $depth;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderColumn = $column;
        $this->orderDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    public function searchColumn(string $column): self
    {
        $this->searchColumn = $column;

        return $this;
    }

    /**
     * Define una expresión SQL cruda para la búsqueda (en vez de una sola columna).
     * Permite buscar sobre datos concatenados o calculados. Ej:
     *   ->searchExpression("first_name || ' ' || last_name || ' ' || rut")
     */
    public function searchExpression(string $expression): self
    {
        $this->searchExpression = $expression;

        return $this;
    }

    public function prepareQuery(callable $callback): self
    {
        $this->prepareQueryMethod = $callback;

        return $this;
    }

    public function enableDebug(): self
    {
        $this->debug = true;

        return $this;
    }

    public function enableIncludeTrashed(): self
    {
        $this->enableIncludeTrashed = true;

        return $this;
    }

    public function disableIdColumn(): self
    {
        $this->addIdColumn = false;

        return $this;
    }

    /** Agrega canales realtime de otras tablas (alias corto; DataTable usa realtimeListen()). */
    public function listen(array $modelClasses): self
    {
        $this->realtimeListen = array_merge($this->realtimeListen, $modelClasses);

        return $this;
    }

    public function realtime(bool $enabled = true): self
    {
        $this->realtimeEnabled = $enabled;

        return $this;
    }

    private function resolveChannelsMeta(): array
    {
        if (! $this->realtimeEnabled) {
            return [];
        }

        $channels = ['entity.'.(new $this->modelClass)->getTable()];

        foreach ($this->realtimeListen as $extra) {
            try {
                $channels[] = 'entity.'.(new $extra)->getTable();
            } catch (\Throwable) {
            }
        }

        return ['channels' => array_values(array_unique($channels))];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rendering
    // ─────────────────────────────────────────────────────────────────────────

    public function render(Request $request): JsonResponse
    {
        $expandRaw = $request->input('expand');
        $expand    = ($expandRaw === null || $expandRaw === '') ? null : $expandRaw;
        $search    = trim((string) $request->input('search', ''));
        $includeTrashed = $this->enableIncludeTrashed && $request->boolean('include_trashed', false);

        // 1. IDs ancla (roots, o hijos directos del expand)
        $anchorIds = $this->getAnchorIds($expand, $includeTrashed, $search);

        // 2. Fetch del subárbol vía CTE
        $flat = empty($anchorIds) ? [] : $this->fetchSubtree($anchorIds, $includeTrashed);

        // 3. has_children batch para todos los nodos retornados
        $allIds = array_column($flat, 'id');
        $hasChildrenSet = $this->fetchHasChildrenSet($allIds, $includeTrashed);
        foreach ($flat as &$row) {
            $row['has_children'] = isset($hasChildrenSet[$row['id']]);
        }
        unset($row);

        // 4. Armar árbol anidado
        $tree = $this->assembleTree($flat, $expand);

        $response = [
            'data' => $tree,
            'meta' => [
                'table_name'    => $this->name,
                'parent_column' => $this->parentColumn,
                'max_depth'     => $this->maxDepth,
                'expand'        => $expand,
                'total_nodes'   => count($flat),
                'request'       => $request->all(),
                ...$this->resolveChannelsMeta(),
            ],
        ];

        if ($this->debug) {
            $response['meta']['debug'] = [
                'anchor_ids' => $anchorIds,
                'flat_rows'  => $flat,
            ];
        }

        return response()->json($response);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * IDs anclas (raíces o hijos directos de `expand`) aplicando prepareQuery
     * + soft deletes + search + orden.
     */
    private function getAnchorIds($expand, bool $includeTrashed, string $search): array
    {
        $query = $this->modelClass::query();

        if ($includeTrashed && $this->modelUsesSoftDeletes()) {
            $query->withTrashed();
        }

        if ($expand === null) {
            $query->whereNull($this->parentColumn);
        } else {
            $query->where($this->parentColumn, $expand);
        }

        if ($search !== '' && ($this->searchExpression || $this->searchColumn)) {
            $expr = $this->searchExpression ?? $this->q($this->searchColumn);
            $query->whereRaw("LOWER(({$expr})) LIKE ?", ['%'.strtolower($search).'%']);
        }

        if (is_callable($this->prepareQueryMethod)) {
            ($this->prepareQueryMethod)($query);
        }

        $query->orderBy($this->orderColumn, $this->orderDirection);

        return $query->pluck('id')->all();
    }

    /**
     * CTE recursivo: dados los IDs ancla, devuelve filas planas con `depth`
     * hasta maxDepth - 1 (depth empieza en 0 para ancla).
     */
    private function fetchSubtree(array $anchorIds, bool $includeTrashed): array
    {
        $model     = new $this->modelClass;
        $table     = $model->getTable();
        $parentCol = $this->parentColumn;
        $cols      = $this->resolveColumns();

        $softDeletes = $this->modelUsesSoftDeletes();
        $childTrashedClause = ($softDeletes && ! $includeTrashed) ? "AND s.deleted_at IS NULL" : '';

        // SELECTs simétricos: lista de columnas sin prefijo en anchor, con `s.` en recursive
        $anchorCols    = implode(', ', array_map(fn ($c) => $this->q($c),         $cols));
        $recursiveCols = implode(', ', array_map(fn ($c) => 's.'.$this->q($c),    $cols));
        $finalCols     = implode(', ', array_map(fn ($c) => 't.'.$this->q($c),    $cols));

        // Placeholders para IN(...)
        $placeholders = implode(',', array_fill(0, count($anchorIds), '?'));
        $maxDepthMinus1 = $this->maxDepth - 1;

        $sql = "
            WITH RECURSIVE tree AS (
                SELECT {$anchorCols}, 0 AS depth
                FROM {$this->q($table)}
                WHERE {$this->q('id')} IN ({$placeholders})
                UNION ALL
                SELECT {$recursiveCols}, t.depth + 1 AS depth
                FROM {$this->q($table)} s
                INNER JOIN tree t ON s.{$this->q($parentCol)} = t.{$this->q('id')}
                WHERE t.depth < {$maxDepthMinus1}
                {$childTrashedClause}
            )
            SELECT {$finalCols}, t.depth
            FROM tree t
            ORDER BY t.depth ASC, t.{$this->q($this->orderColumn)} {$this->orderDirection}
        ";

        $rows = DB::select($sql, $anchorIds);

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * Devuelve [parent_id => true] para nodos con al menos un hijo (no soft-deleted).
     */
    private function fetchHasChildrenSet(array $nodeIds, bool $includeTrashed): array
    {
        if (empty($nodeIds)) {
            return [];
        }

        $query = $this->modelClass::query()
            ->select($this->parentColumn)
            ->whereIn($this->parentColumn, $nodeIds)
            ->groupBy($this->parentColumn);

        if ($this->modelUsesSoftDeletes() && $includeTrashed) {
            $query->withTrashed();
        }

        $parents = $query->pluck($this->parentColumn)->all();

        return array_flip($parents);
    }

    /**
     * Convierte la lista plana en árbol anidado.
     * - Si $expand === null: roots = filas con parent_id NULL.
     * - Si $expand !== null: roots = filas con parent_id == $expand (hijos directos del expand).
     */
    private function assembleTree(array $flatRows, $expand): array
    {
        $byId = [];
        foreach ($flatRows as $row) {
            $row['children'] = [];
            $byId[$row['id']] = $row;
        }

        $isRoot = function ($row) use ($expand) {
            $parent = $row[$this->parentColumn] ?? null;
            if ($expand === null) {
                return $parent === null;
            }
            return (string) $parent === (string) $expand;
        };

        // Adjuntar hijos
        foreach ($flatRows as $row) {
            if ($isRoot($row)) {
                continue;
            }
            $parentId = $row[$this->parentColumn];
            if (isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$row['id']];
            }
        }

        // Recolectar roots
        $tree = [];
        foreach ($flatRows as $row) {
            if ($isRoot($row)) {
                $tree[] = &$byId[$row['id']];
            }
        }

        // Cleanup: nodos en el último nivel no exponen `children` (lazy pendiente)
        $cleanup = function (&$node) use (&$cleanup) {
            if ($node['depth'] >= $this->maxDepth - 1) {
                unset($node['children']);
            } else {
                foreach ($node['children'] as &$child) {
                    $cleanup($child);
                }
                unset($child);
            }
        };
        foreach ($tree as &$root) {
            $cleanup($root);
        }

        return $tree;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveColumns(): array
    {
        $cols = array_values(array_filter($this->columns, fn ($c) => ! in_array($c, $this->hiddenColumns, true)));
        if ($this->addIdColumn && ! in_array('id', $cols, true)) {
            array_unshift($cols, 'id');
        }
        if (! in_array($this->parentColumn, $cols, true)) {
            $cols[] = $this->parentColumn;
        }

        return $cols;
    }

    private function modelUsesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->modelClass), true);
    }

    private function q(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
