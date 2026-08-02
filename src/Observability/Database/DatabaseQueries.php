<?php

namespace Innertia\Observability\Database;

use Illuminate\Support\Facades\DB;

class DatabaseQueries
{
    public function collect(int $limit = 20): array
    {
        $reason = PgStatStatements::reason();
        if ($reason !== null) {
            return ['available' => false, 'reason' => $reason, 'top' => []];
        }
        try {
            $rows = DB::select(
                "SELECT queryid::text AS id, left(query, 300) AS query, calls,
                        round(total_exec_time::numeric, 2) AS total_ms,
                        round(mean_exec_time::numeric, 2) AS mean_ms, rows
                 FROM pg_stat_statements
                 ORDER BY total_exec_time DESC LIMIT ?",
                [$limit]
            );
            return [
                'available' => true,
                'reason'    => null,
                'top'       => array_map(fn ($r) => [
                    'id'       => $r->id,
                    'query'    => $r->query,
                    'calls'    => (int) $r->calls,
                    'total_ms' => (float) $r->total_ms,
                    'mean_ms'  => (float) $r->mean_ms,
                    'rows'     => (int) $r->rows,
                ], $rows),
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => $e->getMessage(), 'top' => []];
        }
    }
}
