<?php

namespace Innertia\Telemetry\Health;

use Illuminate\Support\Facades\DB;

class SystemHealth
{
    public function collect(): array
    {
        return [
            'memory' => $this->memory(),
            'cpu'    => $this->cpu(),
            'disk'   => $this->disk(),
            'queue'  => $this->queue(),
            'php'    => $this->php(),
            'db'     => $this->db(),
        ];
    }

    private function memory(): array
    {
        $used  = memory_get_usage(true);
        $limit = $this->parseMemoryLimit((string) ini_get('memory_limit'));

        return [
            'used_mb'  => round($used / 1048576, 2),
            'peak_mb'  => round(memory_get_peak_usage(true) / 1048576, 2),
            'limit_mb' => $limit ? round($limit / 1048576, 2) : null,
            'percent'  => $limit ? round(($used / $limit) * 100, 1) : null,
        ];
    }

    private function cpu(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [null, null, null];
        return ['1min' => $load[0] ?? null, '5min' => $load[1] ?? null, '15min' => $load[2] ?? null];
    }

    private function disk(): array
    {
        $free  = @disk_free_space('/') ?: 0;
        $total = @disk_total_space('/') ?: 0;
        return [
            'free_gb'  => round($free / 1073741824, 2),
            'total_gb' => round($total / 1073741824, 2),
            'percent'  => $total ? round((($total - $free) / $total) * 100, 1) : null,
        ];
    }

    private function queue(): array
    {
        try {
            return [
                'pending' => DB::table('jobs')->count(),
                'failed'  => DB::table('failed_jobs')->count(),
            ];
        } catch (\Throwable) {
            return ['pending' => null, 'failed' => null];
        }
    }

    private function php(): array
    {
        return [
            'version' => PHP_VERSION,
            'laravel' => app()->version(),
            'env'     => app()->environment(),
            'debug'   => (bool) config('app.debug', false),
        ];
    }

    private function db(): array
    {
        try {
            DB::connection()->getPdo();
            $pending = 0;
            try {
                $pending = count(app('migrator')->getMigrationFiles(app('migrator')->paths()))
                    - count(app('migrator')->getRepository()->getRan());
            } catch (\Throwable) {
                $pending = null;
            }
            return ['up' => true, 'pending_migrations' => $pending];
        } catch (\Throwable $e) {
            return ['up' => false, 'error' => $e->getMessage()];
        }
    }

    public function parseMemoryLimit(string $limit): ?int
    {
        if ($limit === '-1' || $limit === '') return null;

        $value = (int) $limit;
        return match (strtolower(substr($limit, -1))) {
            'g'     => $value * 1073741824,
            'm'     => $value * 1048576,
            'k'     => $value * 1024,
            default => $value,
        };
    }
}
