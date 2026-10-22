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
        // Laravel 13 defaults getTableListing() to schema-qualified names
        // (e.g. "main.test_orders" / "public.charges"). We want bare table names,
        // so disable schema qualification explicitly.
        $tables = $schema->getTableListing(schemaQualified: false);

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
