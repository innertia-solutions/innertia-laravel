<?php

namespace Innertia\Observability\Database;

use Illuminate\Support\Facades\DB;

class DatabaseTables
{
    public function collect(int $limit = 20): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return ['driver' => $driver, 'tables' => []];
        }
        try {
            $rows = DB::select(
                "SELECT relname AS name,
                        pg_total_relation_size(relid) AS bytes,
                        n_live_tup, n_dead_tup,
                        CASE WHEN (n_live_tup + n_dead_tup) > 0
                             THEN round(100.0 * n_dead_tup / (n_live_tup + n_dead_tup), 1)
                             ELSE 0 END AS bloat_pct,
                        last_autovacuum, last_analyze
                 FROM pg_stat_user_tables
                 ORDER BY n_dead_tup DESC LIMIT ?",
                [$limit]
            );
            return [
                'driver' => $driver,
                'tables' => array_map(fn ($r) => [
                    'name'            => $r->name,
                    'bytes'           => (int) $r->bytes,
                    'live'            => (int) $r->n_live_tup,
                    'dead'            => (int) $r->n_dead_tup,
                    'bloat_pct'       => (float) $r->bloat_pct,
                    'last_autovacuum' => $r->last_autovacuum,
                    'last_analyze'    => $r->last_analyze,
                ], $rows),
            ];
        } catch (\Throwable) {
            return ['driver' => $driver, 'tables' => []];
        }
    }
}
