<?php

namespace Innertia\Devtools\Dbms;

use Illuminate\Support\Facades\DB;

class RowBrowser
{
    private const ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'];

    /**
     * @param  array<int, array{column: string, operator: string, value: mixed}>  $filters
     */
    public static function browse(
        string  $table,
        int     $page       = 1,
        int     $perPage    = 50,
        array   $filters    = [],
        string  $sortBy     = 'id',
        string  $sortDir    = 'asc',
        ?string $connection = null,
    ): array {
        $db      = DB::connection($connection);
        $columns = $db->getSchemaBuilder()->getColumnListing($table);

        // Sanitize sort: direction whitelisted, column validated against schema
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';
        $sortBy  = in_array($sortBy, $columns, true) ? $sortBy : 'id';

        $query = $db->table($table);

        foreach ($filters as $filter) {
            $col      = $filter['column'];
            $operator = strtolower($filter['operator'] ?? '=');

            if (! in_array($col, $columns, true)) {
                continue; // skip filters on unknown columns
            }
            if (! in_array($operator, self::ALLOWED_OPERATORS, true)) {
                $operator = '=';
            }

            $query->where($col, $operator, $filter['value']);
        }

        $total = $query->count();
        $rows  = (clone $query)
            ->orderBy($sortBy, $sortDir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toArray();

        return [
            'data'         => $rows,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / max($perPage, 1))),
        ];
    }
}
