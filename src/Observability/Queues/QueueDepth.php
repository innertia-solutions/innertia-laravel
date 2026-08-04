<?php

namespace Innertia\Observability\Queues;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class QueueDepth
{
    public static function collect(): array
    {
        $conn   = config('queue.default');
        $driver = config("queue.connections.$conn.driver");
        try {
            return match ($driver) {
                'database' => self::database(),
                'redis'    => self::redis($conn),
                default    => self::degraded($driver, 'Driver de cola no inspeccionable: ' . $driver),
            };
        } catch (\Throwable $e) {
            return self::degraded($driver, $e->getMessage());
        }
    }

    private static function degraded(?string $driver, string $reason): array
    {
        return ['driver' => $driver, 'available' => false, 'reason' => $reason, 'queues' => [], 'total' => null];
    }

    private static function database(): array
    {
        $rows = DB::table('jobs')->selectRaw('queue, count(*) as n')->groupBy('queue')->get();
        $queues = $rows->map(fn ($r) => ['queue' => $r->queue, 'count' => (int) $r->n])->all();
        return ['driver' => 'database', 'available' => true, 'queues' => $queues, 'total' => array_sum(array_column($queues, 'count'))];
    }

    private static function redis(string $conn): array
    {
        $redisConn = config("queue.connections.$conn.connection", 'default');
        $r = Redis::connection($redisConn);

        $names = [];
        foreach ((array) $r->keys('queues:*') as $key) {
            $pos = strpos($key, 'queues:');
            if ($pos === false) continue;
            $rest = substr($key, $pos + 7);
            $base = explode(':', $rest)[0];
            if ($base !== '') $names[$base] = true;
        }
        if (! $names) $names = ['default' => true];

        $queues = [];
        foreach (array_keys($names) as $name) {
            $pending  = (int) $r->llen("queues:$name");
            $delayed  = (int) $r->zcard("queues:$name:delayed");
            $reserved = (int) $r->zcard("queues:$name:reserved");
            $queues[] = ['queue' => $name, 'count' => $pending + $delayed + $reserved];
        }
        return ['driver' => 'redis', 'available' => true, 'queues' => $queues, 'total' => array_sum(array_column($queues, 'count'))];
    }
}
