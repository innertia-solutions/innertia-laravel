<?php

namespace Innertia\Devtools\Dbms;

use Illuminate\Support\Facades\DB;

class RowBrowser
{
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
        $query = DB::connection($connection)->table($table);

        foreach ($filters as $filter) {
            $query->where(
                $filter['column'],
                $filter['operator'] ?? '=',
                $filter['value'],
            );
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
            'last_page'    => (int) ceil($total / max($perPage, 1)),
        ];
    }
}
