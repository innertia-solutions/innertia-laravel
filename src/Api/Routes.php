<?php

namespace Innertia\Api;

use Illuminate\Support\Facades\Route;
use Innertia\Api\Http\Controllers\ApiKeysController;
use Innertia\Api\Http\Controllers\OrganizationsController;

/**
 * Rutas del modo API (sin users / sin JWT). La autenticación de las rutas de
 * negocio es por API key (middleware 'verify.api.key'); la administración de
 * organizaciones y API keys va protegida por 'olimpo.auth'.
 *
 * No hay Auth\Routes (no hay login), ni Notifications/Teams (no hay usuarios).
 *
 *   // api.php (modo api)
 *   \Innertia\Api\Routes::register();   // admin de orgs/api-keys (olimpo.auth)
 *
 *   // rutas de negocio del producto:
 *   Route::middleware(\Innertia\Api\Routes::privateMiddleware())->group(function () {
 *       // $request->attributes->get('organization') disponible
 *   });
 */
class Routes
{
    /** Admin de organizaciones + API keys (protegido por olimpo.auth). */
    public static function register(string $prefix = 'olimpo'): void
    {
        Route::prefix($prefix)->middleware('olimpo.auth')->group(function () {
            // Organizations
            Route::get   ('organizations',                          [OrganizationsController::class, 'index']);
            Route::post  ('organizations',                          [OrganizationsController::class, 'store']);
            Route::get   ('organizations/{organization}',           [OrganizationsController::class, 'show']);
            Route::post  ('organizations/{organization}/children',  [OrganizationsController::class, 'storeChild']);
            Route::patch ('organizations/{organization}/suspend',   [OrganizationsController::class, 'suspend']);
            Route::patch ('organizations/{organization}/reactivate',[OrganizationsController::class, 'reactivate']);
            Route::delete('organizations/{organization}',           [OrganizationsController::class, 'destroy']);

            // API Keys
            Route::get   ('organizations/{organization}/api-keys',          [ApiKeysController::class, 'index']);
            Route::post  ('organizations/{organization}/api-keys',          [ApiKeysController::class, 'store']);
            Route::delete('organizations/{organization}/api-keys/{apiKey}', [ApiKeysController::class, 'revoke']);
        });
    }

    /**
     * Stack para rutas de negocio del modo api: verifica la API key e inyecta
     * organization + api_key en el request.
     *
     * @return array<string>
     */
    public static function privateMiddleware(): array
    {
        return ['verify.api.key'];
    }
}
