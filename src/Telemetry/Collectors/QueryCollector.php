<?php

namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class QueryCollector
{
    public static function handle(
        TelemetryCollector $collector,
        string  $sql,
        array   $bindings,
        float   $durationMs,
        string  $connection,
    ): void {
        $collector->record(new TelemetryEvent(
            type: 'query',
            payload: [
                'sql'         => $sql,
                'bindings'    => $bindings,
                'duration_ms' => $durationMs,
                'connection'  => $connection,
            ],
            context: self::buildContext($collector),
            durationMs: $durationMs,
        ));
    }

    private static function buildContext(TelemetryCollector $collector): array
    {
        try {
            $route = request()?->method() . ' ' . request()?->path();
            $env = app()->environment();
            $source = request()?->header('X-Innertia-Source', 'cli');
        } catch (\Throwable) {
            $route = null;
            $env = 'testing';
            $source = 'cli';
        }

        return [
            'tenant'  => $collector->tenant(),
            'user_id' => null,
            'route'   => $route,
            'env'     => $env,
            'source'  => $source,
        ];
    }
}
