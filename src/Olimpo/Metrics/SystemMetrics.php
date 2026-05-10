<?php

namespace Innertia\Olimpo\Metrics;

class SystemMetrics
{
    public static function collect(): array
    {
        return [
            'memory'  => static::memory(),
            'cpu'     => static::cpu(),
            'disk'    => static::disk(),
            'queue'   => static::queue(),
            'php'     => static::php(),
        ];
    }

    private static function memory(): array
    {
        $used  = memory_get_usage(true);
        $peak  = memory_get_peak_usage(true);
        $limit = static::parseMemoryLimit(ini_get('memory_limit'));

        return [
            'used'       => $used,
            'peak'       => $peak,
            'limit'      => $limit,
            'used_mb'    => round($used / 1024 / 1024, 2),
            'peak_mb'    => round($peak / 1024 / 1024, 2),
            'limit_mb'   => $limit ? round($limit / 1024 / 1024, 2) : null,
            'percent'    => $limit ? round(($used / $limit) * 100, 1) : null,
        ];
    }

    private static function cpu(): array
    {
        $load = sys_getloadavg();

        return [
            '1min'  => $load[0] ?? null,
            '5min'  => $load[1] ?? null,
            '15min' => $load[2] ?? null,
        ];
    }

    private static function disk(): array
    {
        $free  = @disk_free_space('/') ?: 0;
        $total = @disk_total_space('/') ?: 0;
        $used  = $total - $free;

        return [
            'free_gb'  => round($free / 1024 / 1024 / 1024, 2),
            'used_gb'  => round($used / 1024 / 1024 / 1024, 2),
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'percent'  => $total ? round(($used / $total) * 100, 1) : null,
        ];
    }

    private static function queue(): array
    {
        try {
            $failed  = \DB::table('failed_jobs')->count();
            $pending = \DB::table('jobs')->count();
        } catch (\Throwable) {
            $failed  = null;
            $pending = null;
        }

        return [
            'pending' => $pending,
            'failed'  => $failed,
        ];
    }

    private static function php(): array
    {
        return [
            'version'  => PHP_VERSION,
            'laravel'  => app()->version(),
            'env'      => app()->environment(),
            'debug'    => config('app.debug', false),
        ];
    }

    private static function parseMemoryLimit(string $limit): ?int
    {
        if ($limit === '-1') return null;

        $unit  = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
