<?php

namespace Innertia\Telemetry\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Telemetry\Collectors\RequestCollector;
use Innertia\Telemetry\TelemetryCollector;
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

        // La persistencia local se conecta en Task 7 (TelemetryStore).
        $collector->flush();
    }
}
