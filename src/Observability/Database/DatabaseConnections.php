<?php

namespace Innertia\Observability\Database;

use Illuminate\Support\Facades\DB;

class DatabaseConnections
{
    public function collect(): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return $this->degraded($driver);
        }
        try {
            $states = DB::select(
                "SELECT coalesce(state, 'unknown') AS state, count(*) AS n
                 FROM pg_stat_activity WHERE backend_type = 'client backend'
                 GROUP BY state"
            );
            $byState = [];
            $total = 0;
            foreach ($states as $s) {
                $byState[$s->state] = (int) $s->n;
                $total += (int) $s->n;
            }
            $max = DB::selectOne("SELECT setting::int AS max FROM pg_settings WHERE name = 'max_connections'");

            $long = DB::select(
                "SELECT pid, coalesce(state,'') AS state,
                        extract(epoch FROM (now() - query_start))::int AS seconds,
                        left(query, 200) AS query
                 FROM pg_stat_activity
                 WHERE backend_type = 'client backend' AND state <> 'idle'
                   AND query_start IS NOT NULL
                 ORDER BY query_start ASC LIMIT 10"
            );
            $idleTx = DB::select(
                "SELECT pid, extract(epoch FROM (now() - state_change))::int AS seconds, left(query,200) AS query
                 FROM pg_stat_activity
                 WHERE backend_type = 'client backend' AND state = 'idle in transaction'
                 ORDER BY state_change ASC LIMIT 10"
            );

            return [
                'driver'   => $driver,
                'total'    => $total,
                'max'      => $max ? (int) $max->max : null,
                'by_state' => $byState,
                'long_running'        => array_map(fn ($r) => (array) $r, $long),
                'idle_in_transaction' => array_map(fn ($r) => (array) $r, $idleTx),
            ];
        } catch (\Throwable) {
            return $this->degraded($driver);
        }
    }

    private function degraded(string $driver): array
    {
        return [
            'driver' => $driver, 'total' => null, 'max' => null,
            'by_state' => [], 'long_running' => [], 'idle_in_transaction' => [],
        ];
    }
}
