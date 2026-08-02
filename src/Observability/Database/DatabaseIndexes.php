<?php

namespace Innertia\Observability\Database;

use Illuminate\Support\Facades\DB;

class DatabaseIndexes
{
    public function collect(int $limit = 20): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return ['driver' => $driver, 'unused' => [], 'scans' => []];
        }
        try {
            $unused = DB::select(
                "SELECT relname AS table_name, indexrelname AS index_name,
                        idx_scan, pg_relation_size(indexrelid) AS bytes
                 FROM pg_stat_user_indexes
                 WHERE idx_scan = 0
                 ORDER BY pg_relation_size(indexrelid) DESC LIMIT ?",
                [$limit]
            );
            $scans = DB::select(
                "SELECT relname AS table_name, coalesce(seq_scan,0) AS seq_scan, coalesce(idx_scan,0) AS idx_scan
                 FROM pg_stat_user_tables
                 ORDER BY seq_scan DESC LIMIT ?",
                [$limit]
            );
            return [
                'driver' => $driver,
                'unused' => array_map(fn ($r) => [
                    'table' => $r->table_name, 'index' => $r->index_name,
                    'scans' => (int) $r->idx_scan, 'bytes' => (int) $r->bytes,
                ], $unused),
                'scans' => array_map(fn ($r) => [
                    'table' => $r->table_name, 'seq_scan' => (int) $r->seq_scan, 'idx_scan' => (int) $r->idx_scan,
                ], $scans),
            ];
        } catch (\Throwable) {
            return ['driver' => $driver, 'unused' => [], 'scans' => []];
        }
    }
}
