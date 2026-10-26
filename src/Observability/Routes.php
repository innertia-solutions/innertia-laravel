<?php

namespace Innertia\Observability;

use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\DatabaseController;
use Innertia\Observability\Http\Controllers\QueuesController;
use Innertia\Telemetry\Http\Controllers\ObservabilityChannelsController;
use Innertia\Observability\Http\Controllers\ErrorsController;
use Innertia\Observability\Http\Controllers\OverviewController;
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
    public static function register(?string $prefix = null): void
    {
        $prefix ??= config('innertia.observability.prefix', 'platform/observability');

        Route::prefix($prefix)->group(function () {
            // Base de datos (Fase 1 — lazy por tab)
            Route::get('database/overview',    [DatabaseController::class, 'overview']);
            Route::get('database/connections', [DatabaseController::class, 'connections']);
            Route::get('database/queries',     [DatabaseController::class, 'queries']);
            Route::get('database/indexes',     [DatabaseController::class, 'indexes']);
            Route::get('database/tables',      [DatabaseController::class, 'tables']);

            // Overview (KPIs)
            Route::get('overview', [OverviewController::class, 'index']);

            // Errores (Sentry): index + detalle + status
            Route::get ('errors',             [ErrorsController::class, 'index']);
            Route::get ('errors/{id}',        [ErrorsController::class, 'show']);
            Route::post('errors/{id}/status', [ErrorsController::class, 'status']);
            Route::get ('logs',               [ObservabilityLogsController::class, 'index']);
            Route::get ('channels',           [ObservabilityChannelsController::class, 'index']);

            // Colas & workers
            Route::get   ('queues/overview',          [QueuesController::class, 'overview']);
            Route::get   ('queues/workers',           [QueuesController::class, 'workers']);
            Route::post  ('queues/restart',           [QueuesController::class, 'restart']);
            Route::get   ('queues/failed',            [QueuesController::class, 'failed']);
            Route::post  ('queues/failed/retry-all',  [QueuesController::class, 'retryAll']);
            Route::post  ('queues/failed/flush',      [QueuesController::class, 'flush']);
            Route::post  ('queues/failed/{id}/retry', [QueuesController::class, 'retry']);
            Route::delete('queues/failed/{id}',       [QueuesController::class, 'destroy']);
        });
    }
}
