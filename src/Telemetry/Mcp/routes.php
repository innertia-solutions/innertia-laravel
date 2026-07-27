<?php

use Innertia\Telemetry\Mcp\ObservabilityServer;
use Laravel\Mcp\Facades\Mcp;

if (! config('innertia.telemetry.mcp.enabled', true)) {
    return;
}

// Local (stdio) — para dev por producto vía .mcp.json: `php artisan mcp:start innertia`.
Mcp::local('innertia', ObservabilityServer::class);

// HTTP (platform) — opcional.
// ⚠️ SEGURIDAD: las tools exponen stack traces, payloads, ips, emails y tenants.
// NO habilitar mcp.web=true en un entorno desplegado sin configurar
// `innertia.telemetry.mcp.web_middleware` con el guard de plataforma del producto
// (p.ej. ['api', 'auth:api', EnsurePlatformAdmin::class]). El default ['api'] NO
// autentica identidad de plataforma por sí solo.
if (config('innertia.telemetry.mcp.web', false)) {
    Mcp::web('/platform/mcp', ObservabilityServer::class)
        ->middleware(config('innertia.telemetry.mcp.web_middleware', ['api']));
}
