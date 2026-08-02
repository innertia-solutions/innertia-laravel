<?php

namespace Innertia\Telemetry\Health;

use Illuminate\Support\Facades\DB;

class DatabaseCapacity
{
    public function collect(int $topTables = 10): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            return [
                'driver'         => $driver,
                'database_bytes' => null,
                'tables'         => [],
                'connections'    => ['used' => null, 'max' => null],
            ];
        }

        return [
            'driver'         => $driver,
            'database_bytes' => $this->databaseBytes(),
            'tables'         => $this->topTables($topTables),
            'connections'    => $this->connections(),
        ];
    }

    private function databaseBytes(): ?int
    {
        $row = DB::selectOne('SELECT pg_database_size(current_database()) AS bytes');
        return $row ? (int) $row->bytes : null;
    }

    private function topTables(int $limit): array
    {
        $rows = DB::select(
            'SELECT relname AS name,
                    pg_total_relation_size(relid) AS bytes,
                    n_live_tup AS rows
             FROM pg_stat_user_tables
             ORDER BY pg_total_relation_size(relid) DESC
             LIMIT ?',
            [$limit]
        );

        return array_map(fn ($r) => [
            'name'  => $r->name,
            'bytes' => (int) $r->bytes,
            'rows'  => (int) $r->rows,
        ], $rows);
    }

    private function connections(): array
    {
        $used = DB::selectOne('SELECT count(*) AS used FROM pg_stat_activity');
        $max  = DB::selectOne("SELECT setting::int AS max FROM pg_settings WHERE name = 'max_connections'");

        return [
            'used' => $used ? (int) $used->used : null,
            'max'  => $max ? (int) $max->max : null,
        ];
    }
}
