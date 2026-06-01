<?php

namespace Innertia\DataTable;

use Innertia\DataTable\Contracts\ExporterInterface;
use Innertia\DataTable\Exporters\CsvExporter;
use Innertia\DataTable\Exporters\XlsxExporter;
use Innertia\DataTable\Exporters\JsonExporter;
use Innertia\DataTable\Exporters\PdfExporter;
use Innertia\DataTable\Exceptions\UnsupportedExportFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DataTable
{
    private string $name;

    private array $columns = [];

    private array $relationships = [];

    private bool $debug = false;

    private array $calculatedColumns = [];

    /** Expresiones SQL crudas marcadas como buscables (alias => expr). */
    private array $searchableExpressions = [];

    private $prepareQueryMethod = null;

    private array $jsonColumns = [];

    private array $hiddenColumns = [];

    private array $resolvedColumns = [];

    private $sourceClass;

    private bool $addIdColumn = true;

    private bool $enableIncludeTrashed = false;

    private bool $enableOnlyTrashed = false;

    private bool $enableExport = false;

    private array $pluckColumns = [];

    private bool $enableCache = false;

    private int $cacheTimeout = 3600; // 1 hora por defecto

    private bool $enableList = false;

    /** @var callable|null Hook opcional para telemetría. Recibe: name, rows, duration_ms */
    public static $onRender = null;

    private ?string $listKeyColumn = null;

    private $listValueColumns = null;

    // Cache keys
    private const CACHE_PREFIX = 'datatable_metadata_';

    private const RELATION_CACHE_KEY = 'relation_types_';

    private const COLUMNS_CACHE_KEY = 'table_columns_';

    // Exporters disponibles
    private const EXPORTERS = [
        'pdf' => PdfExporter::class,
        'xlsx' => XlsxExporter::class,
        'csv' => CsvExporter::class,
        'json' => JsonExporter::class,
    ];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Define relationships to include in the DataTable
     *
     * Ejemplo de uso:
     * ->relationships([
     *     'user' => ['*'],                    // Todas las columnas del usuario
     *     'roles' => ['id', 'name'],          // Solo id y name de los roles
     *     'permissions' => ['*'],             // Todas las columnas de permisos
     *     'profile' => ['name', 'email']      // Solo name y email del perfil
     * ])
     *
     * @param  array  $relationships  Array donde la clave es el nombre de la relación
     *                                y el valor es un array de columnas. Use ['*'] para
     *                                seleccionar todas las columnas disponibles.
     */
    public function relationships(array $relationships): self
    {
        $this->relationships = $relationships;

        return $this;
    }

    public function hiddenColumns(array $hiddenColumns): self
    {
        $this->hiddenColumns = $hiddenColumns;

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

    /**
     * add query param ?include_trashed=true to include soft deleted records
     */
    public function enableIncludeTrashed(): self
    {
        $this->enableIncludeTrashed = true;

        return $this;
    }

    /**
     * add query param ?trashed=true to show only soft deleted records
     */
    public function enableOnlyTrashed(): self
    {
        $this->enableOnlyTrashed = true;

        return $this;
    }

    public function enableExport(): self
    {
        $this->enableExport = true;

        return $this;
    }

    /**
     * Habilita modo lista para endpoints que necesitan formato {id, key, value}
     *
     * Ejemplos de uso:
     * ->enableList() // Autodetecta key (name, title, etc) y value (description, email, etc)
     * ->enableList('name') // key=name, value=autodetectado
     * ->enableList('name', 'description') // key=name, value=description
     * ->enableList('name', ['first_name', 'last_name']) // key=name, value={first_name: '...', last_name: '...'}
     *
     * @param  string|null  $keyColumn  Columna para el campo 'key'
     * @param  string|array|null  $valueColumns  Columna(s) para el campo 'value'.
     *                                           Si es array, retorna un objeto con cada columna como propiedad
     */
    public function enableList(?string $keyColumn = null, $valueColumns = null): self
    {
        $this->enableList = true;
        $this->listKeyColumn = $keyColumn;
        $this->listValueColumns = $valueColumns;

        return $this;
    }

    /**
     * Habilita modo lista pero concatena las columnas del value como string
     * Útil cuando necesitas mostrar información combinada en una sola línea
     *
     * Ejemplo: ->enableListConcatenated('name', ['code', 'price'], ' - ')
     * Resultado: value = "LAP001 - $999.99"
     */
    public function enableListConcatenated(?string $keyColumn = null, $valueColumns = null, string $separator = ' '): self
    {
        $this->enableList = true;
        $this->listKeyColumn = $keyColumn;
        $this->listValueColumns = ['_concatenated' => $valueColumns, '_separator' => $separator];

        return $this;
    }

    public function disableIdColumn(): self
    {
        $this->addIdColumn = false;

        return $this;
    }

    public function addCalculatedColumn(string $alias, $callback, bool $searchable = false): self
    {
        $this->calculatedColumns[$alias] = $callback;

        // Si es buscable y la expresión es SQL cruda (string), registrarla para search.
        if ($searchable && is_string($callback)) {
            $this->searchableExpressions[$alias] = $callback;
        }

        return $this;
    }

    /**
     * Registra una expresión SQL cruda buscable que NO se muestra como columna.
     * Útil para buscar sobre datos concatenados o de tablas relacionadas — ej.
     * `addSearchableColumn('student_search', "(SELECT s.first_name||' '||s.last_name||' '||s.rut FROM students s WHERE s.id = enrollments.student_id)")`.
     * El término de búsqueda se aplica como `CAST((expr) AS TEXT) ILIKE %term%`.
     */
    public function addSearchableColumn(string $alias, string $expression): self
    {
        $this->searchableExpressions[$alias] = $expression;

        return $this;
    }

    public function addPluckColumn(string $alias, string $relation, string $column): self
    {
        $this->pluckColumns[$alias] = [
            'relation' => $relation,
            'column' => $column,
        ];

        return $this;
    }

    public function disableCache(): self
    {
        $this->enableCache = false;

        return $this;
    }

    public function setCacheTimeout(int $seconds): self
    {
        $this->cacheTimeout = $seconds;

        return $this;
    }

    /**
     * Cache de tipos de relación para evitar instanciación repetida
     */
    private function getCachedRelationType(string $relation, string $modelClass): ?string
    {
        if (! $this->enableCache) {
            return $this->detectRelationType($relation);
        }

        $cacheKey = self::CACHE_PREFIX.self::RELATION_CACHE_KEY.md5($modelClass.'_'.$relation);

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($relation) {
            return $this->detectRelationType($relation);
        });
    }

    /**
     * Cache de columnas de tabla
     */
    private function getCachedTableColumns(string $table): array
    {
        if (! $this->enableCache) {
            return DB::getSchemaBuilder()->getColumnListing($table);
        }

        $cacheKey = self::CACHE_PREFIX.self::COLUMNS_CACHE_KEY.$table;

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($table) {
            return DB::getSchemaBuilder()->getColumnListing($table);
        });
    }

    /**
     * Maneja las relaciones de manera optimizada con eager loading
     */
    private function handleRelation(Builder $query, string $column, array $meta): void
    {
        $relationInstance = $query->getModel()->$column();
        $resolvedColumns = $this->resolveRelationColumns($column, $meta['columns']);

        match ($meta['relation']) {
            'belongsTo' => $this->handleBelongsTo($query, $column, $resolvedColumns),
            'belongsToMany' => $this->handleBelongsToMany($query, $column, $resolvedColumns, $relationInstance),
            'hasMany' => $this->handleHasMany($query, $column, $resolvedColumns, $relationInstance),
            'hasOne' => $this->handleHasOne($query, $column, $resolvedColumns, $relationInstance),
            default => null
        };
    }

    private function handleBelongsTo(Builder $query, string $column, array $resolvedColumns): void
    {
        $cols = $this->addIdColumn && ! in_array('id', $resolvedColumns)
            ? array_merge(['id'], $resolvedColumns)
            : $resolvedColumns;

        $query->with([$column => fn ($q) => $q->select($cols)]);

        $this->addBelongsToJsonColumn($query, $column, $resolvedColumns);
    }

    private function handleBelongsToMany(Builder $query, string $column, array $resolvedColumns, $relationInstance): void
    {
        $cols = $this->addIdColumn && ! in_array('id', $resolvedColumns)
            ? array_merge(['id'], $resolvedColumns)
            : $resolvedColumns;

        // Eager loading para evitar N+1
        $query->with([$column => fn ($q) => $q->select($cols)]);

        // Para DataTables, también necesitamos la subconsulta JSON para búsquedas
        $this->addBelongsToManyJsonColumn($query, $column, $resolvedColumns, $relationInstance);
    }

    private function handleHasMany(Builder $query, string $column, array $resolvedColumns, $relationInstance): void
    {
        // Para hasMany, usar subconsulta optimizada
        $this->addHasManyJsonColumn($query, $column, $resolvedColumns, $relationInstance);
    }

    private function handleHasOne(Builder $query, string $column, array $resolvedColumns, $relationInstance): void
    {
        // Para hasOne, usar subconsulta optimizada
        $this->addHasOneJsonColumn($query, $column, $resolvedColumns, $relationInstance);
    }

    /**
     * Maneja las columnas pluck
     */
    private function handlePluckColumn(Builder $query, string $alias, array $meta): void
    {
        $relation = $meta['relation'];
        $column = $meta['column'];

        try {
            $relationInstance = $query->getModel()->$relation();

            if ($relationInstance instanceof BelongsToMany) {
                $this->addBelongsToManyPluckColumn($query, $alias, $relationInstance, $column);
            } elseif ($relationInstance instanceof HasMany) {
                $this->addHasManyPluckColumn($query, $alias, $relationInstance, $column);
            }

            $this->jsonColumns[] = $alias;
        } catch (\Exception $e) {
            // Si hay error, agregar columna vacía
            $this->addCalculatedColumn("\"{$alias}\"", "'[]'");
            $this->jsonColumns[] = $alias;
        }
    }

    /**
     * Métodos auxiliares para subconsultas optimizadas
     */
    private function addBelongsToManyJsonColumn(Builder $query, string $column, array $resolvedColumns, $relationInstance): void
    {
        $relatedTable = $relationInstance->getRelated()->getTable();
        $pivotTable = $relationInstance->getTable();
        $foreignPivotKey = $relationInstance->getForeignPivotKeyName();
        $relatedPivotKey = $relationInstance->getRelatedPivotKeyName();
        $parentTable = $relationInstance->getParent()->getTable();

        $jsonQuery = $this->buildJsonQuery($relatedTable, $resolvedColumns, true);
        $subquery = DB::table($relatedTable)
            ->join($pivotTable, "{$relatedTable}.id", '=', "{$pivotTable}.{$relatedPivotKey}")
            ->whereColumn("{$pivotTable}.{$foreignPivotKey}", '=', "{$parentTable}.id")
            ->selectRaw($jsonQuery);

        $this->addCalculatedColumn("\"{$column}\"", "({$subquery->toSql()})");
        $this->jsonColumns[] = $column;
    }

    private function addHasManyJsonColumn(Builder $query, string $column, array $resolvedColumns, $relationInstance): void
    {
        $relatedTable = $relationInstance->getRelated()->getTable();
        $foreignKey = $relationInstance->getForeignKeyName();
        $localKey = $relationInstance->getLocalKeyName();
        $parentTable = $relationInstance->getParent()->getTable();

        $jsonQuery = $this->buildJsonQuery($relatedTable, $resolvedColumns, true);
        $subquery = DB::table($relatedTable)
            ->selectRaw($jsonQuery)
            ->whereColumn("{$relatedTable}.{$foreignKey}", '=', "{$parentTable}.{$localKey}");

        $this->addCalculatedColumn("\"{$column}\"", "({$subquery->toSql()})");
        $this->jsonColumns[] = $column;
    }

    private function addHasOneJsonColumn(Builder $query, string $column, array $resolvedColumns, $relationInstance): void
    {
        $relatedTable = $relationInstance->getRelated()->getTable();
        $foreignKey = $relationInstance->getForeignKeyName();
        $localKey = $relationInstance->getLocalKeyName();
        $parentTable = $relationInstance->getParent()->getTable();

        $jsonQuery = $this->buildJsonQuery($relatedTable, $resolvedColumns, false);
        $subquery = DB::table($relatedTable)
            ->selectRaw($jsonQuery)
            ->whereColumn("{$relatedTable}.{$foreignKey}", '=', "{$parentTable}.{$localKey}")
            ->orderByDesc('created_at')
            ->limit(1);

        $this->addCalculatedColumn("\"{$column}\"", "({$subquery->toSql()})");
        $this->jsonColumns[] = $column;
        $this->hiddenColumns[] = $column;
    }

    private function addBelongsToJsonColumn(Builder $query, string $column, array $resolvedColumns): void
    {
        try {
            $relationInstance = $query->getModel()->$column();
            $relatedTable = $relationInstance->getRelated()->getTable();
            $foreignKey = $relationInstance->getForeignKeyName();
            $ownerKey = $relationInstance->getOwnerKeyName();
            $parentTable = $relationInstance->getParent()->getTable();

            $jsonQuery = $this->buildJsonQuery($relatedTable, $resolvedColumns, false);
            $subquery = DB::table($relatedTable)
                ->selectRaw($jsonQuery)
                ->whereColumn("{$relatedTable}.{$ownerKey}", '=', "{$parentTable}.{$foreignKey}");

            $this->addCalculatedColumn("\"{$column}\"", "({$subquery->toSql()})");
            $this->jsonColumns[] = $column;
        } catch (\Exception $e) {
            // Si hay error, agregar columna vacía
            $this->addCalculatedColumn("\"{$column}\"", "'{}'");
            $this->jsonColumns[] = $column;
        }
    }

    private function addBelongsToManyPluckColumn(Builder $query, string $alias, $relationInstance, string $column): void
    {
        $relatedTable = $relationInstance->getRelated()->getTable();
        $pivotTable = $relationInstance->getTable();
        $foreignPivotKey = $relationInstance->getForeignPivotKeyName();
        $relatedPivotKey = $relationInstance->getRelatedPivotKeyName();
        $parentTable = $relationInstance->getParent()->getTable();

        if (DB::getDriverName() === 'pgsql') {
            $jsonQuery = "COALESCE(jsonb_agg({$relatedTable}.{$column}), '[]'::jsonb)";
        } else {
            $jsonQuery = "COALESCE(JSON_ARRAYAGG({$relatedTable}.{$column}), JSON_ARRAY())";
        }

        $subquery = DB::table($relatedTable)
            ->join($pivotTable, "{$relatedTable}.id", '=', "{$pivotTable}.{$relatedPivotKey}")
            ->whereColumn("{$pivotTable}.{$foreignPivotKey}", '=', "{$parentTable}.id")
            ->selectRaw($jsonQuery);

        $this->addCalculatedColumn("\"{$alias}\"", "({$subquery->toSql()})");
    }

    private function addHasManyPluckColumn(Builder $query, string $alias, $relationInstance, string $column): void
    {
        $relatedTable = $relationInstance->getRelated()->getTable();
        $foreignKey = $relationInstance->getForeignKeyName();
        $localKey = $relationInstance->getLocalKeyName();
        $parentTable = $relationInstance->getParent()->getTable();

        if (DB::getDriverName() === 'pgsql') {
            $jsonQuery = "COALESCE(jsonb_agg({$relatedTable}.{$column}), '[]'::jsonb)";
        } else {
            $jsonQuery = "COALESCE(JSON_ARRAYAGG({$relatedTable}.{$column}), JSON_ARRAY())";
        }

        $subquery = DB::table($relatedTable)
            ->selectRaw($jsonQuery)
            ->whereColumn("{$relatedTable}.{$foreignKey}", '=', "{$parentTable}.{$localKey}");

        $this->addCalculatedColumn("\"{$alias}\"", "({$subquery->toSql()})");
    }

    /**
     * Aplica búsqueda optimizada con mejores estrategias según el tipo de columna
     */
    private function applyOptimizedSearch(Builder $query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            foreach ($this->resolvedColumns as $column => $meta) {
                match ($meta['type']) {
                    'direct' => $this->applyDirectColumnSearch($q, $column, $search),
                    'relation' => $this->applyRelationSearch($q, $column, $search),
                    'pluck' => $this->applyPluckSearch($q, $column, $search),
                    'searchexpr' => $this->applySearchExpression($q, $meta['expr'], $search),
                    default => null
                };
            }
        });
    }

    private function applyDirectColumnSearch(Builder $query, string $column, string $search): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->orWhereRaw("CAST(\"$column\" AS text) ILIKE ?", ["%{$search}%"]);
        } else {
            $query->orWhere($column, 'like', "%{$search}%");
        }
    }

    private function applySearchExpression(Builder $query, string $expr, string $search): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->orWhereRaw("CAST(({$expr}) AS TEXT) ILIKE ?", ["%{$search}%"]);
        } else {
            $query->orWhereRaw("LOWER(CAST(({$expr}) AS CHAR)) LIKE ?", ['%'.strtolower($search).'%']);
        }
    }

    private function applyRelationSearch(Builder $query, string $column, string $search): void
    {
        // Solo buscar en columnas JSON si están definidas como calculadas
        if (isset($this->calculatedColumns[$column])) {
            $query->orWhereRaw($this->getOptimizedJsonSearchQuery($column), ["%{$search}%"]);
        }
    }

    private function applyPluckSearch(Builder $query, string $column, string $search): void
    {
        // Para columnas pluck, buscar en el array JSON
        if (isset($this->calculatedColumns[$column])) {
            $query->orWhereRaw($this->getOptimizedJsonSearchQuery($column), ["%{$search}%"]);
        }
    }

    /**
     * Query de búsqueda JSON optimizada según el driver
     */
    private function getOptimizedJsonSearchQuery(string $column): string
    {
        $columnRef = $this->calculatedColumns[$column] ?? $column;

        return DB::getDriverName() === 'pgsql'
            ? "CAST({$columnRef} AS TEXT) ILIKE ?"  // ILIKE es case-insensitive en PostgreSQL
            : "JSON_SEARCH({$columnRef}, 'one', ?) IS NOT NULL";  // Más eficiente en MySQL
    }

    private function prepareColumns(): void
    {
        foreach ($this->columns as $column) {
            $this->resolvedColumns[$column] = ['type' => 'direct'];
        }

        foreach ($this->relationships as $relation => $cols) {
            $this->resolvedColumns[$relation] = [
                'type' => 'relation',
                'relation' => $this->getCachedRelationType($relation, $this->getModelClass()),
                'columns' => $cols,
            ];
        }

        // Procesar columnas pluck
        foreach ($this->pluckColumns as $alias => $config) {
            $this->resolvedColumns[$alias] = [
                'type' => 'pluck',
                'relation' => $config['relation'],
                'column' => $config['column'],
            ];
        }

        // Expresiones SQL crudas buscables (no se muestran; solo participan del search).
        foreach ($this->searchableExpressions as $alias => $expr) {
            $this->resolvedColumns['searchexpr::' . $alias] = [
                'type' => 'searchexpr',
                'expr' => $expr,
            ];
        }
    }

    private function detectRelationType(string $relation): ?string
    {
        try {
            $model = new ($this->getModelClass())();
            $relationInstance = $model->$relation();

            return match (true) {
                $relationInstance instanceof HasOne => 'hasOne',
                $relationInstance instanceof BelongsTo => 'belongsTo',
                $relationInstance instanceof BelongsToMany => 'belongsToMany',
                $relationInstance instanceof HasMany => 'hasMany',
                $relationInstance instanceof MorphOne => 'morphOne',
                default => null
            };
        } catch (\Exception $e) {
            // Si hay error detectando el tipo de relación, devolver null
            return null;
        }
    }

    /**
     * Resuelve las columnas para una relación, manejando el wildcard '*'
     */
    private function resolveRelationColumns(string $relation, array $columns): array
    {
        // Si no hay asterisco, devolver las columnas tal como están
        if (! in_array('*', $columns)) {
            return $columns;
        }

        // Si no hay sourceClass definido, devolver columnas básicas
        if (! $this->sourceClass) {
            return ['id', 'name', 'created_at', 'updated_at'];
        }

        try {
            $model = new ($this->getModelClass())();
            $relationInstance = $model->$relation();
            $relatedModel = $relationInstance->getRelated();
            $relatedTable = $relatedModel->getTable();

            $allColumns = $this->getCachedTableColumns($relatedTable);
            // Filtrar columnas que generalmente no queremos mostrar
            $filteredColumns = array_filter($allColumns, function ($col) {
                return ! in_array($col, ['password', 'remember_token', 'verified_at']);
            });

            return array_values($filteredColumns);
        } catch (\Exception $e) {
            // Si hay error obteniendo las columnas, usar las básicas
            return ['id', 'name', 'created_at', 'updated_at'];
        }
    }

    /**
     * Autodetecta la columna key basada en patrones comunes
     */
    private function autodetectKeyColumn(): string
    {
        $commonKeyColumns = ['name', 'title', 'label', 'code', 'slug', 'username', 'email'];

        // Buscar en las columnas definidas
        foreach ($commonKeyColumns as $column) {
            if (in_array($column, $this->columns)) {
                return $column;
            }
        }

        // Si no encuentra, usar la primera columna que no sea ID
        $availableColumns = array_filter($this->columns, fn ($col) => $col !== 'id');

        return ! empty($availableColumns) ? reset($availableColumns) : 'id';
    }

    /**
     * Autodetecta la(s) columna(s) value basada en patrones comunes
     */
    private function autodetectValueColumns()
    {
        $commonValueColumns = ['description', 'details', 'content', 'notes', 'email', 'phone', 'address'];

        // Buscar en las columnas definidas
        foreach ($commonValueColumns as $column) {
            if (in_array($column, $this->columns)) {
                return $column;
            }
        }

        // Si no encuentra description-like, usar la segunda columna disponible
        $availableColumns = array_filter($this->columns, fn ($col) => ! in_array($col, ['id', $this->listKeyColumn]));

        return ! empty($availableColumns) ? reset($availableColumns) : $this->listKeyColumn;
    }

    /**
     * Procesa los datos para formato lista
     */
    private function processListFormat($items): array
    {
        $keyColumn = $this->listKeyColumn ?? $this->autodetectKeyColumn();
        $valueColumns = $this->listValueColumns ?? $this->autodetectValueColumns();

        return collect($items)->map(function ($item) use ($keyColumn, $valueColumns) {
            $processed = [
                'id' => $item->id ?? $item['id'] ?? null,
                'key' => $this->getItemValue($item, $keyColumn),
            ];

            if (is_array($valueColumns)) {
                // Verificar si es concatenación especial
                if (isset($valueColumns['_concatenated'])) {
                    $columns = $valueColumns['_concatenated'];
                    $separator = $valueColumns['_separator'] ?? ' ';

                    if (is_array($columns)) {
                        $values = array_map(fn ($col) => $this->getItemValue($item, $col), $columns);
                        $processed['value'] = implode($separator, array_filter($values));
                    } else {
                        $processed['value'] = $this->getItemValue($item, $columns);
                    }
                } else {
                    // Crear objeto con cada columna como propiedad
                    $valueObject = [];
                    foreach ($valueColumns as $col) {
                        $valueObject[$col] = $this->getItemValue($item, $col);
                    }
                    $processed['value'] = $valueObject;
                }
            } else {
                $processed['value'] = $this->getItemValue($item, $valueColumns);
            }

            return $processed;
        })->toArray();
    }

    /**
     * Obtiene el valor de una columna del item (maneja objetos y arrays)
     */
    private function getItemValue($item, string $column)
    {
        $value = null;

        if (is_object($item)) {
            $value = $item->$column ?? $item->{$column} ?? null;
        } elseif (is_array($item)) {
            $value = $item[$column] ?? null;
        }

        // Si el valor parece ser JSON y estamos en modo lista, decodificarlo automáticamente
        if (is_string($value) && $this->enableList && $this->looksLikeJson($value)) {
            $decoded = json_decode($value, true);

            return $decoded !== null ? $decoded : $value;
        }

        return $value;
    }

    private function buildJsonQuery(string $tableName, array $columns, bool $isArray = true): string
    {
        $columnsList = implode(', ', array_map(fn ($col) => "'{$col}', {$tableName}.{$col}", $columns));

        if (DB::getDriverName() === 'pgsql') {
            if ($isArray) {
                return "COALESCE(jsonb_agg(jsonb_build_object({$columnsList})), '[]'::jsonb)";
            } else {
                return "jsonb_build_object({$columnsList})";
            }
        } else {
            // MySQL
            if ($isArray) {
                return "COALESCE(JSON_ARRAYAGG(JSON_OBJECT({$columnsList})), JSON_ARRAY())";
            } else {
                return "JSON_OBJECT({$columnsList})";
            }
        }
    }

    private function getModelClass(): string
    {
        if (! $this->sourceClass) {
            throw new \InvalidArgumentException('Source class not defined. Call getQuery() or render() first.');
        }

        return is_string($this->sourceClass) ? $this->sourceClass : get_class($this->sourceClass);
    }

    private function resolveHistoryMeta(): array
    {
        if (! $this->sourceClass) {
            return ['has_history' => false, 'entity_type' => null];
        }

        try {
            $modelClass = $this->sourceClass instanceof Builder
                ? get_class($this->sourceClass->getModel())
                : $this->getModelClass();

            $traits     = class_uses_recursive($modelClass);
            $hasHistory = in_array(\Innertia\Platform\Traits\HasHistory::class, $traits)
                       || in_array(\Innertia\Platform\Traits\Auditable::class, $traits);

            return [
                'has_history' => $hasHistory,
                'entity_type' => $hasHistory ? class_basename($modelClass) : null,
            ];
        } catch (\Throwable) {
            return ['has_history' => false, 'entity_type' => null];
        }
    }

    public function getQuery(string|Builder $source, ?string $search = '', array $sortColumns = [], bool $includeTrashed = false, bool $onlyTrashed = false, array $filters = []): Builder
    {
        $this->sourceClass = $source;

        // Asegurar que search sea una cadena
        $search = $search ?? '';

        $query = $source instanceof Builder
            ? $source
            : (new $source)->newQuery();

        // Preparar columnas después de asignar sourceClass
        $this->prepareColumns();

        if ($this->prepareQueryMethod) {
            $query = call_user_func($this->prepareQueryMethod, $query);
        }

        // Manejo de soft deletes
        if ($this->enableIncludeTrashed && $includeTrashed) {
            $this->applySoftDeletesQuery($query, 'include_trashed');
        } elseif ($this->enableOnlyTrashed && $onlyTrashed) {
            $this->applySoftDeletesQuery($query, 'only_trashed');
        }

        $selectColumns = [];
        if ($this->addIdColumn) {
            $selectColumns = ['id'];
        }

        // Agregar deleted_at si se están incluyendo o mostrando solo registros eliminados
        if (($this->enableIncludeTrashed && $includeTrashed) || ($this->enableOnlyTrashed && $onlyTrashed)) {
            $selectColumns[] = 'deleted_at';
            // Agregar deleted_at a las columnas resueltas para búsqueda y ordenamiento
            $this->resolvedColumns['deleted_at'] = ['type' => 'direct'];
        }

        foreach ($this->resolvedColumns as $column => $meta) {
            if ($meta['type'] === 'direct') {
                $selectColumns[] = $column;
            }

            if ($meta['type'] === 'relation') {
                $this->handleRelation($query, $column, $meta);
            }

            if ($meta['type'] === 'pluck') {
                $this->handlePluckColumn($query, $column, $meta);
            }
        }

        $query->select($selectColumns);

        foreach ($this->calculatedColumns as $alias => $callback) {
            $query->addSelect(DB::raw("{$callback} as {$alias}"));
        }

        // Búsqueda optimizada
        if ($search !== '') {
            $this->applyOptimizedSearch($query, $search);
        }

        // Filtros enriquecidos (field + operator + value)
        if (!empty($filters)) {
            $this->applyEnrichedFilters($query, $filters);
        }

        foreach ($sortColumns as $sort) {
            $column = $sort['column'] ?? null;
            $direction = $sort['direction'] ?? 'asc';

            if ($column) {
                if (isset($this->calculatedColumns[$column])) {
                    $query->orderByRaw("{$this->calculatedColumns[$column]} {$direction}");
                } else {
                    $query->orderBy($column, $direction);
                }
            }
        }

        return $query;
    }

    /**
     * Método principal que maneja tanto renderizado JSON como exportaciones
     */
    public function render(string|Builder $source, Request $request, ?string $orderBy = null, string $orderDirection = 'asc')
    {
        $this->sourceClass = $source;

        // Verificar si es una exportación
        $exportType = $request->query('exportType');
        if ($exportType && $this->enableExport) {
            return $this->handleExport($source, $request, $exportType);
        }

        // Renderizado normal (JSON)
        return $this->renderJson($source, $request, $orderBy, $orderDirection);
    }

    /**
     * Maneja las exportaciones según el tipo solicitado
     */
    private function handleExport(string|Builder $source, Request $request, string $exportType)
    {
        if (! isset(self::EXPORTERS[$exportType])) {
            throw new UnsupportedExportFormatException($exportType);
        }

        $exportQuery = $request->boolean('exportQuery', true);
        $exportPagination = $request->boolean('exportPagination', false);
        $includeTrashed = $request->boolean('include_trashed', false);
        $onlyTrashed = $request->boolean('trashed', false);

        if ($exportQuery) {
            // Exportar con filtros (lo que se ve en la tabla)
            $search = $request->input('search', '') ?? '';
            $sortColumns = $request->input('sortColumns', []) ?? [];
            $filters = $request->input('filters', []) ?? [];
            $query = $this->getQuery($source, $search, $sortColumns, $includeTrashed, $onlyTrashed, $filters);

            if ($exportPagination) {
                // Exportar solo la página actual
                $page = $request->input('page', 1);
                $perPage = $request->input('perPage', 10);
                $paginator = $query->paginate($perPage, ['*'], 'page', $page);
                // Usar map en lugar de collect()->map() para mejor performance
                $data = $paginator->getCollection()->map(fn ($item) => $this->toSnakeCase($item))->toArray();
            } else {
                // Exportar todo lo filtrado
                $data = $query->get()->map(fn ($item) => $this->toSnakeCase($item))->toArray();
            }
        } else {
            // Exportar toda la data (sin filtros)
            $query = $this->getQuery($source, '', [], $includeTrashed, $onlyTrashed);
            $data = $query->get()->map(fn ($item) => $this->toSnakeCase($item))->toArray();
        }

        // Instanciar el exporter correspondiente
        $exporterClass = self::EXPORTERS[$exportType];
        /** @var ExporterInterface $exporter */
        $exporter = new $exporterClass;

        $columns = array_filter($this->columns, fn ($col) => ! in_array($col, $this->hiddenColumns));
        if ($this->addIdColumn && ! in_array('id', $columns)) {
            array_unshift($columns, 'id');
        }

        if ($this->enableIncludeTrashed && $includeTrashed) {
            if (! in_array('deleted_at', $columns)) {
                $columns[] = 'deleted_at';
            }
        } elseif ($this->enableOnlyTrashed && $onlyTrashed) {
            if (! in_array('deleted_at', $columns)) {
                $columns[] = 'deleted_at';
            }
        }

        return $exporter->export(
            $data,
            $request,
            $columns,
            $this->relationships,
            $this->name,
        );
    }

    /**
     * Renderizado JSON original (para la tabla web)
     */
    private function renderJson(string|Builder $source, Request $request, ?string $orderBy = null, string $orderDirection = 'asc')
    {
        $list = $request->boolean('list', false);
        $search = $request->input('search', '') ?? '';
        $sortColumns = $request->input('sortColumns', []) ?? [];
        $page = $request->input('page', 1);
        $perPage = $request->input('perPage', 10);

        if ($orderBy && empty($sortColumns)) {
            $sortColumns = [['column' => $orderBy, 'direction' => $orderDirection]];
        }

        $includeTrashed = $request->boolean('include_trashed', false);
        $onlyTrashed = $request->boolean('trashed', false);
        $filters = $request->input('filters', []) ?? [];
        $query = $this->getQuery($source, $search, $sortColumns, $includeTrashed, $onlyTrashed, $filters);

        if ($list) {
            $results = $query->get();

            // Si está habilitado el modo lista, devolver formato especial
            if ($this->enableList) {
                $transformedResults = $this->processListFormat($results);

                return response()->json($transformedResults);
            }

            // Usar map directamente en la Collection para mejor performance
            $transformedResults = $results->map(fn ($item) => $this->toSnakeCase($item));

            return response()->json($transformedResults);
        }

        $table = $query->paginate($perPage, ['*'], 'page', $page);

        // Si está habilitado el modo lista Y se solicita list=true, transformar los datos
        if ($this->enableList && $list) {
            $listData = $this->processListFormat($table->items());
            $table = $table->toArray();
            $table['data'] = $listData;
        } else {
            // Usar transform en lugar de getCollection()->transform() para mejor performance
            $table->transform(fn ($item) => $this->toSnakeCase($item));
        }

        $response = [
            'data' => ($this->enableList && $list) ? $table['data'] : $table->items(),
            'meta' => [
                'total' => ($this->enableList && $list) ? $table['total'] : $table->total(),
                'per_page' => ($this->enableList && $list) ? $table['per_page'] : $table->perPage(),
                'current_page' => ($this->enableList && $list) ? $table['current_page'] : $table->currentPage(),
                'last_page' => ($this->enableList && $list) ? $table['last_page'] : $table->lastPage(),
                'from' => ($this->enableList && $list) ? $table['from'] : $table->firstItem(),
                'to' => ($this->enableList && $list) ? $table['to'] : $table->lastItem(),
                'first_page_url' => ($this->enableList && $list) ? $table['first_page_url'] : $table->url(1),
                'last_page_url' => ($this->enableList && $list) ? $table['last_page_url'] : $table->url($table->lastPage() ?? 1),
                'next_page_url' => ($this->enableList && $list) ? $table['next_page_url'] : $table->nextPageUrl(),
                'prev_page_url' => ($this->enableList && $list) ? $table['prev_page_url'] : $table->previousPageUrl(),
                'path' => ($this->enableList && $list) ? $table['path'] : $table->path(),
                'request' => $request->all(),
                'table_name' => $this->name,
                ...$this->resolveHistoryMeta(),
            ],
        ];

        if ($this->debug) {
            $response['meta']['query'] = $query->toRawSql();
        }

        // Hook de telemetría — no acopla DataTable con el módulo Telemetry
        if (static::$onRender !== null) {
            try {
                $rowCount = is_array($response['data']) ? count($response['data']) : 0;
                (static::$onRender)($this->name, $rowCount, 0.0);
            } catch (\Throwable) {
                // Silencioso
            }
        }

        return response()->json($response);
    }

    /**
     * Aplica filtros enriquecidos: [{ field, operator, value }, ...]
     * Soporta columnas directas y columnas calculadas (addCalculatedColumn).
     */
    private function applyEnrichedFilters(Builder $query, array $filters): void
    {
        // Build a normalized map: 'status' => raw SQL expression (or null for direct columns)
        $allowedFields = [];

        foreach ($this->columns as $col) {
            $allowedFields[$col] = null;
        }
        foreach (array_keys($this->relationships) as $rel) {
            $allowedFields[$rel] = null;
        }
        foreach ($this->calculatedColumns as $quotedKey => $expr) {
            // Keys are stored as '"status"' (PostgreSQL-quoted) — normalize to 'status'
            $normalized = trim($quotedKey, '"');
            $allowedFields[$normalized] = $expr;
        }
        foreach (array_keys($this->pluckColumns) as $key) {
            $allowedFields[$key] = null;
        }

        foreach ($filters as $filter) {
            $field    = $filter['field']    ?? null;
            $operator = $filter['operator'] ?? null;
            $value    = $filter['value']    ?? null;

            if (! $field || ! $operator || $value === null || $value === '') {
                continue;
            }

            if (! array_key_exists($field, $allowedFields)) {
                continue;
            }

            $rawExpr = $allowedFields[$field]; // null = direct column, string = SQL expression
            $this->applyFilterOperator($query, $field, $operator, $value, $rawExpr);
        }
    }

    private function applyFilterOperator(Builder $query, string $field, string $operator, mixed $value, ?string $rawExpr = null): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($rawExpr !== null) {
            // Calculated column — filter using the raw SQL expression
            match ($operator) {
                'contains'           => $isPgsql
                    ? $query->whereRaw("({$rawExpr})::text ILIKE ?", ["%{$value}%"])
                    : $query->whereRaw("CAST(({$rawExpr}) AS CHAR) LIKE ?", ["%{$value}%"]),
                'starts_with'        => $isPgsql
                    ? $query->whereRaw("({$rawExpr})::text ILIKE ?", ["{$value}%"])
                    : $query->whereRaw("CAST(({$rawExpr}) AS CHAR) LIKE ?", ["{$value}%"]),
                'equals', 'is', 'eq' => $query->whereRaw("({$rawExpr}) = ?", [$value]),
                'is_not', 'neq'      => $query->whereRaw("({$rawExpr}) != ?", [$value]),
                'before'             => $isPgsql
                    ? $query->whereRaw("({$rawExpr})::date < ?", [$value])
                    : $query->whereRaw("DATE({$rawExpr}) < ?", [$value]),
                'after'              => $isPgsql
                    ? $query->whereRaw("({$rawExpr})::date > ?", [$value])
                    : $query->whereRaw("DATE({$rawExpr}) > ?", [$value]),
                'gt'                 => $query->whereRaw("({$rawExpr}) > ?", [$value]),
                'lt'                 => $query->whereRaw("({$rawExpr}) < ?", [$value]),
                default              => null,
            };

            return;
        }

        // Direct column
        match ($operator) {
            'contains'           => $isPgsql
                ? $query->whereRaw("CAST(\"{$field}\" AS text) ILIKE ?", ["%{$value}%"])
                : $query->where($field, 'like', "%{$value}%"),
            'starts_with'        => $isPgsql
                ? $query->whereRaw("CAST(\"{$field}\" AS text) ILIKE ?", ["{$value}%"])
                : $query->where($field, 'like', "{$value}%"),
            'equals', 'is', 'eq' => $query->where($field, $value),
            'is_not', 'neq'      => $query->where($field, '!=', $value),
            'before'             => $query->whereDate($field, '<', $value),
            'after'              => $query->whereDate($field, '>', $value),
            'gt'                 => $query->where($field, '>', $value),
            'lt'                 => $query->where($field, '<', $value),
            default              => null,
        };
    }

    private function toSnakeCase($item)
    {
        if (! is_object($item)) {
            return $item;
        }

        // Usar Collections para transformación más eficiente
        return collect($item->getAttributes())
            ->mapWithKeys(function ($value, $key) {
                $camelKey = Str::snake($key);

                // Optimización: decodificar JSON solo si es necesario
                if (is_string($value) && $this->looksLikeJson($value)) {
                    $value = json_decode($value);
                }

                return [$camelKey => $value];
            })
            ->pipe(fn ($collection) => (object) $collection->toArray());
    }

    /**
     * Verificación optimizada de JSON
     */
    private function looksLikeJson(string $value): bool
    {
        // Verificación rápida antes de json_decode
        if (strlen($value) < 2) {
            return false;
        }

        $firstChar = $value[0];
        $lastChar = $value[-1];

        // Solo intentar decodificar si parece JSON
        if (($firstChar === '{' && $lastChar === '}') ||
            ($firstChar === '[' && $lastChar === ']')
        ) {
            json_decode($value);

            return json_last_error() === JSON_ERROR_NONE;
        }

        return false;
    }

    /**
     * Aplica withTrashed(), onlyTrashed() o incluye registros eliminados de manera optimizada
     */
    private function applySoftDeletesQuery(Builder $query, string $mode): void
    {
        $model = $query->getModel();

        // Usar cache para verificar si soporta SoftDeletes
        $supportsSoftDeletes = $this->modelSupportsSoftDeletes($model);

        if ($supportsSoftDeletes) {
            // Usar métodos nativos de Eloquent (más eficiente)
            match ($mode) {
                'include_trashed' => $query->withTrashed(),
                'only_trashed' => $query->onlyTrashed(),
                default => $query->withTrashed()
            };
        } else {
            // Fallback para tablas sin SoftDeletes trait pero con columna deleted_at
            $this->handleDeletedWithoutTrait($query, $mode);
        }
    }

    /**
     * Verifica si el modelo soporta SoftDeletes usando cache
     */
    private function modelSupportsSoftDeletes($model): bool
    {
        if (! $this->enableCache) {
            return $this->checkSoftDeletesTrait($model);
        }

        $modelClass = get_class($model);
        $cacheKey = self::CACHE_PREFIX.'soft_deletes_'.md5($modelClass);

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($model) {
            return $this->checkSoftDeletesTrait($model);
        });
    }

    private function checkSoftDeletesTrait($model): bool
    {
        $traits = class_uses_recursive($model);

        return in_array(SoftDeletes::class, $traits);
    }

    /**
     * Maneja registros eliminados para modelos sin SoftDeletes trait
     */
    private function handleDeletedWithoutTrait(Builder $query, string $mode): void
    {
        $table = $query->getModel()->getTable();

        // Usar cache para verificar si la tabla tiene deleted_at
        if ($this->tableHasDeletedAtColumn($table)) {
            // Remover global scopes que puedan interferir
            $query->withoutGlobalScope('deleted_at');

            match ($mode) {
                'include_trashed' => null, // No agregar condición WHERE
                'only_trashed' => $query->whereNotNull('deleted_at'),
                default => null
            };
        }
    }

    /**
     * Verifica si la tabla tiene columna deleted_at usando cache
     */
    private function tableHasDeletedAtColumn(string $table): bool
    {
        if (! $this->enableCache) {
            return $this->checkDeletedAtColumn($table);
        }

        $cacheKey = self::CACHE_PREFIX.'has_deleted_at_'.$table;

        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($table) {
            return $this->checkDeletedAtColumn($table);
        });
    }

    private function checkDeletedAtColumn(string $table): bool
    {
        try {
            $columns = $this->getCachedTableColumns($table);

            return in_array('deleted_at', $columns);
        } catch (\Exception $e) {
            return false;
        }
    }
}
