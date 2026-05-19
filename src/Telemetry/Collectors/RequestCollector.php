<?php

namespace Innertia\Telemetry\Collectors;

use Illuminate\Http\Request;
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;
use Symfony\Component\HttpFoundation\Response;

class RequestCollector
{
    public static function handle(
        TelemetryCollector $collector,
        Request  $request,
        Response $response,
        float    $durationMs,
    ): void {
        $collector->record(new TelemetryEvent(
            type: 'request',
            payload: [
                'method'      => $request->method(),
                'path'        => $request->path(),
                'status'      => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'ip'          => $request->ip(),
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => $request->method() . ' ' . $request->path(),
                'env'     => self::getEnvironment(),
                'source'  => $request->header('X-Innertia-Source', 'cli'),
            ],
            durationMs: $durationMs,
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
