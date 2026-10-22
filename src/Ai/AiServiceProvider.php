<?php

namespace Innertia\Ai;

use Illuminate\Support\ServiceProvider;
use Innertia\Ai\Mcp\EnsureMcpKey;

/**
 * Wiring del módulo Innertia\Ai. Registra el AiManager como singleton y, cuando
 * la feature está activa (config innertia.ai.enabled), el alias de middleware
 * `ensure.mcp.key` para gatear rutas MCP del producto.
 * Mismo patrón opt-in que PaymentsServiceProvider.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiManager::class);
    }

    public function boot(): void
    {
        if (! AiFeature::isActive()) {
            return;
        }

        $this->app['router']->aliasMiddleware('ensure.mcp.key', EnsureMcpKey::class);
    }
}
