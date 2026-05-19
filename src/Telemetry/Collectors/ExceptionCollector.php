<?php

namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;
use Throwable;

class ExceptionCollector
{
    public static function handle(TelemetryCollector $collector, Throwable $e): void
    {
        $except = config('telemetry.except', []);
        foreach ($except as $class) {
            if ($e instanceof $class) return;
        }

        $collector->record(new TelemetryEvent(
            type: 'exception',
            payload: [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => collect($e->getTrace())
                    ->take(20)
                    ->map(fn ($f) => [
                        'file'     => $f['file'] ?? null,
                        'line'     => $f['line'] ?? null,
                        'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                    ])
                    ->toArray(),
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => request()?->method() . ' ' . request()?->path(),
                'env'     => self::getEnvironment(),
                'source'  => request()?->header('X-Innertia-Source', 'cli'),
            ],
        ));
    }

    private static function getEnvironment(): string
    {
        try {
            return app()->environment();
        } catch (\Throwable) {
            return 'testing';
        }
    }
}
