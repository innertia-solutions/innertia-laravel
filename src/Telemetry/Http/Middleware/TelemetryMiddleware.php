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
        // Robustez de librería: si la telemetría no está activa el collector no
        // está bindeado; no hacer nada (el middleware podría haberse montado a mano).
        if (! app()->bound(TelemetryCollector::class)) {
            return;
        }

        /** @var TelemetryCollector $collector */
        $collector = app(TelemetryCollector::class);

        $durationMs = round((microtime(true) - $this->startTime) * 1000, 2);
        RequestCollector::handle($collector, $request, $response, $durationMs);

        $store = app(\Innertia\Telemetry\Store\TelemetryStore::class);
        $meta = [
            'method'           => $request->method(),
            'route'            => $request->route()?->getName() ?? $request->path(),
            'url'              => $request->fullUrl(),
            'status'           => $response->getStatusCode(),
            'duration_ms'      => (int) round($durationMs),
            'ip'               => $request->ip(),
            'user_agent'       => substr((string) $request->userAgent(), 0, 512),
            // La sanitización es responsabilidad única de PayloadSanitizer (config redact).
            'payload'          => $request->all(),
            'response_summary' => ['status' => $response->getStatusCode()],
        ];

        if (function_exists('defer')) {
            defer(fn () => $store->persist($collector, $meta));
        } else {
            $store->persist($collector, $meta);
        }
    }
}
