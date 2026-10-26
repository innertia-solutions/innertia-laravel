<?php

namespace Innertia\Telemetry\Health;

use Illuminate\Support\Facades\Cache;

/**
 * Latido de los workers de cola: cada worker escribe su timestamp al loopear;
 * el panel lee la lista y marca alive/stale. Permite "¿está vivo mi worker?"
 * sin Horizon. Store de cache por defecto (redis en prod, array en tests).
 */
class WorkerHeartbeat
{
    private const KEY = 'innertia:workers';

    public function ping(?string $connection = null): void
    {
        $id = gethostname().':'.getmypid();
        $store = Cache::store();
        $all = $store->get(self::KEY, []);
        $all[$id] = ['id' => $id, 'connection' => $connection, 'ts' => now()->timestamp];
        $store->put(self::KEY, $all, now()->addMinutes(10));
    }

    /** @return array<int, array{id:string,connection:?string,last_seen:int,alive:bool}> */
    public function list(int $staleSeconds = 30): array
    {
        $all = Cache::store()->get(self::KEY, []);
        $now = now()->timestamp;

        return collect($all)->map(fn ($w) => [
            'id' => $w['id'],
            'connection' => $w['connection'] ?? null,
            'last_seen' => (int) $w['ts'],
            'alive' => ($now - (int) $w['ts']) <= $staleSeconds,
        ])->sortByDesc('last_seen')->values()->all();
    }
}
