<?php

namespace Innertia\Devtools\Dbms;

use Illuminate\Support\Facades\DB;

class TableInspector
{
    /**
     * Returns all tables in the default (or given) connection,
     * each with its column definitions and current row count.
     */
    public static function tables(?string $connection = null): array
    {
        $db     = DB::connection($connection);
        $schema = $db->getSchemaBuilder();
        $tables = $schema->getTableListing();

        return array_values(array_map(
            fn (string $table) => [
                'name'      => $table,
                'row_count' => $db->table($table)->count(),
                'columns'   => $schema->getColumns($table),
            ],
            $tables,
        ));
    }

    /**
     * Returns column definitions for a single table.
     * Each column: ['name', 'type_name', 'type', 'nullable', 'default', ...]
     */
    public static function columns(string $table, ?string $connection = null): array
    {
        return DB::connection($connection)->getSchemaBuilder()->getColumns($table);
    }
}
