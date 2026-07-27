<?php

use Innertia\Telemetry\Mcp\ObservabilityServer;
use Laravel\Mcp\Facades\Mcp;

if (! config('innertia.telemetry.mcp.enabled', true)) {
    return;
}

// Local (stdio) — para dev por producto vía .mcp.json: `php artisan mcp:start innertia`.
Mcp::local('innertia', ObservabilityServer::class);

// HTTP (platform) — opcional, protegido por el guard de plataforma del producto.
if (config('innertia.telemetry.mcp.web', false)) {
    Mcp::web('/platform/mcp', ObservabilityServer::class)->middleware(['api']);
}
