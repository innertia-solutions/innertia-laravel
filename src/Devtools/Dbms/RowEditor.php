<?php

namespace Innertia\Devtools\Dbms;

use Illuminate\Support\Facades\DB;

class RowEditor
{
    public static function update(
        string     $table,
        int|string $id,
        string     $column,
        mixed      $value,
        ?string    $connection = null,
    ): bool {
        $db      = DB::connection($connection);
        $columns = $db->getSchemaBuilder()->getColumnListing($table);

        // Only allow updates to columns that exist in the schema
        if (! in_array($column, $columns, true)) {
            return false;
        }

        $affected = $db->table($table)
            ->where('id', $id)
            ->update([$column => $value]);

        return $affected > 0;
    }
}
