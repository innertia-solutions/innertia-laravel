<?php

namespace Innertia\Olimpo;

use Illuminate\Support\Facades\Route;
use Innertia\Olimpo\Http\Controllers\OlimpoController;
use Innertia\Telemetry\Http\Controllers\TelemetryController;

/**
 * Rutas de administración de la plataforma (Olimpo).
 *
 * Protegidas por el middleware 'olimpo.auth' (header X-Olimpo-Key). El
 * OlimpoServiceProvider las registra automáticamente; un producto también puede
 * llamarlas explícitamente con \Innertia\Olimpo\Routes::register().
 */
class Routes
{
    public static function register(string $prefix = 'olimpo'): void
    {
        Route::prefix($prefix)->middleware('olimpo.auth')->group(function () {
            Route::get('health', [OlimpoController::class, 'health']);

            Route::post  ('tenants',                              [OlimpoController::class, 'createTenant']);
            Route::get   ('tenants/{id}',                         [OlimpoController::class, 'getTenant']);
            Route::delete('tenants/{id}',                         [OlimpoController::class, 'deleteTenant']);
            Route::patch ('tenants/{id}/suspend',                 [OlimpoController::class, 'suspendTenant']);
            Route::patch ('tenants/{id}/reactivate',              [OlimpoController::class, 'reactivateTenant']);
            Route::patch ('tenants/{id}/trial',                   [OlimpoController::class, 'updateTrial']);
            Route::post  ('tenants/{id}/cache/flush',             [OlimpoController::class, 'flushCache']);
            Route::get   ('tenants/{id}/users',                   [OlimpoController::class, 'getTenantUsers']);
            Route::post  ('tenants/{id}/users/{userId}/impersonate', [OlimpoController::class, 'impersonate']);
            Route::get   ('tenants/{id}/backups',                 [OlimpoController::class, 'getTenantBackups']);
            Route::post  ('tenants/{id}/backups',                 [OlimpoController::class, 'createBackup']);

            Route::put   ('tenants/{id}/demo',                    [OlimpoController::class, 'enableDemo']);
            Route::delete('tenants/{id}/demo',                    [OlimpoController::class, 'disableDemo']);

            // Telemetría — recibe batches de eventos de las apps cliente
            Route::post('telemetry', [TelemetryController::class, 'receive']);
        });
    }
}
