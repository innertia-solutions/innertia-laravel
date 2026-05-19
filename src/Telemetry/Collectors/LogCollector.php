<?php

namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class LogCollector
{
    public static function handle(
        TelemetryCollector $collector,
        string $level,
        string $message,
        array  $context = [],
    ): void {
        try {
            $route = request()?->method() . ' ' . request()?->path();
            $env = app()->environment();
            $source = request()?->header('X-Innertia-Source', 'cli');
        } catch (\Throwable) {
            $route = null;
            $env = 'testing';
            $source = 'cli';
        }

        $collector->record(new TelemetryEvent(
            type: 'log',
            payload: [
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => $route,
                'env'     => $env,
                'source'  => $source,
            ],
        ));
    }
}
