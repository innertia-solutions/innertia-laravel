<?php

namespace Innertia\Observability\Database;

use Illuminate\Support\Facades\DB;

class DatabaseOverview
{
    public function collect(): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return $this->degraded($driver);
        }
        try {
            $row = DB::selectOne(
                "SELECT xact_commit, xact_rollback, blks_read, blks_hit,
                        deadlocks, temp_files, temp_bytes
                 FROM pg_stat_database WHERE datname = current_database()"
            );
            $size = DB::selectOne('SELECT pg_database_size(current_database()) AS bytes');
            $hit  = ($row->blks_hit + $row->blks_read) > 0
                ? round(100.0 * $row->blks_hit / ($row->blks_hit + $row->blks_read), 2)
                : null;

            return [
                'driver'          => $driver,
                'database_bytes'  => $size ? (int) $size->bytes : null,
                'cache_hit_ratio' => $hit,
                'commits'         => (int) $row->xact_commit,
                'rollbacks'       => (int) $row->xact_rollback,
                'deadlocks'       => (int) $row->deadlocks,
                'temp_files'      => (int) $row->temp_files,
                'temp_bytes'      => (int) $row->temp_bytes,
            ];
        } catch (\Throwable) {
            return $this->degraded($driver);
        }
    }

    private function degraded(string $driver): array
    {
        return [
            'driver' => $driver, 'database_bytes' => null, 'cache_hit_ratio' => null,
            'commits' => null, 'rollbacks' => null, 'deadlocks' => null,
            'temp_files' => null, 'temp_bytes' => null,
        ];
    }
}
