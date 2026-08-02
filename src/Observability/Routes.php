<?php

namespace Innertia\Observability;

use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\DatabaseController;
use Innertia\Telemetry\Http\Controllers\ObservabilityChannelsController;
use Innertia\Telemetry\Http\Controllers\ObservabilityErrorsController;
use Innertia\Telemetry\Http\Controllers\ObservabilityLogsController;

/**
 * Monta el panel de observabilidad (drop-in). El producto lo llama dentro de un
 * grupo con middleware platform, p.ej.:
 *
 *   Route::middleware(['auth:api','platform'])->group(fn () =>
 *       \Innertia\Observability\Routes::register('observability'));
 *
 * Fase 1: database/* es nuevo; errors/logs/channels se delegan a los controllers
 * de Telemetry (se reemplazan en fases 2/3).
 */
class Routes
{
    public static function register(string $prefix = 'platform/observability'): void
    {
        Route::prefix($prefix)->group(function () {
            // Base de datos (Fase 1 — lazy por tab)
            Route::get('database/overview',    [DatabaseController::class, 'overview']);
            Route::get('database/connections', [DatabaseController::class, 'connections']);
            Route::get('database/queries',     [DatabaseController::class, 'queries']);
            Route::get('database/indexes',     [DatabaseController::class, 'indexes']);
            Route::get('database/tables',      [DatabaseController::class, 'tables']);

            // Errores / logs / canales (reutilizados de Telemetry; se upgradean en Fase 2/3)
            Route::get ('errors',             [ObservabilityErrorsController::class, 'index']);
            Route::post('errors/{id}/status', [ObservabilityErrorsController::class, 'status']);
            Route::get ('logs',               [ObservabilityLogsController::class, 'index']);
            Route::get ('channels',           [ObservabilityChannelsController::class, 'index']);
        });
    }
}
