<?php

namespace Innertia\Telemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Telemetry\Collectors\RequestCollector;
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryExporter;
use Symfony\Component\HttpFoundation\Response;

class TelemetryMiddleware
{
    private float $startTime;

    public function handle(Request $request, Closure $next): Response
    {
        $this->startTime = microtime(true);
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        /** @var TelemetryCollector $collector */
        $collector = app(TelemetryCollector::class);

        $durationMs = round((microtime(true) - $this->startTime) * 1000, 2);
        RequestCollector::handle($collector, $request, $response, $durationMs);

        $sessionId = $collector->sessionId();
        $batch     = $collector->flush();
        $exporter  = new TelemetryExporter(
            olimpoUrl: config('innertia.telemetry.olimpo_url'),
            olimpoKey: config('innertia.telemetry.olimpo_key'),
            appName:   config('innertia.telemetry.app_name', config('app.name', 'app')),
            timeout:   config('innertia.telemetry.timeout', 3),
        );

        if (function_exists('defer')) {
            defer(fn () => $exporter->flush($batch, $sessionId));
        } else {
            $exporter->flush($batch, $sessionId);
        }
    }
}
