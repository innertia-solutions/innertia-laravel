<?php

namespace Innertia\Saas\Auth;

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Auth\Routes as AuthRoutes;
use Innertia\Saas\Middleware\ResolveTenantFromHeader;

/**
 * Rutas de autenticación para modo SaaS — agnósticas de contexto.
 *
 * Monta TODO /auth/* bajo el middleware que resuelve el tenant del header:
 *   - Público: login, otp, 2fa/verify, email/verify, password forgot/reset, oauth
 *   - Autenticado: me, me/permissions, refresh, logout, 2fa enable/disable
 *
 * El contexto NO va en la URL: el login lo recibe en el payload y, una vez
 * emitido el JWT, viaja en el claim 'context'. El comportamiento por contexto
 * (validación, features) lo resuelve el AuthController según config + usuario.
 *
 *   // api.php
 *   \Innertia\Saas\Auth\Routes::register();
 */
class Routes
{
    public static function register(string $prefix = 'auth'): void
    {
        // Auth de plataforma separada (opt-in): pública, fuera del grupo de tenant.
        if (config('innertia.platform.separate_identity')) {
            AuthRoutes::platformRoutes();
        }

        Route::middleware(ResolveTenantFromHeader::class)->group(function () use ($prefix) {
            // Público (resuelve tenant, sin auth)
            AuthRoutes::publicRoutes($prefix);

            // Autenticado (no exige tenant activo: se usa antes/después de elegir tenant)
            Route::middleware(Authenticate::class)->group(function () use ($prefix) {
                AuthRoutes::sessionRoutes($prefix);

                // Tenant-agnóstico: gyms del usuario, sin tenant activo (ruteo post-login)
                Route::get($prefix.'/my-gyms', [\Innertia\Auth\Http\Controllers\MyTenantsController::class, 'index']);

                // Alta self-serve de gym (modo open): crea tenant + deja al caller como admin.
                // Tenant-agnóstico (no exige tenant activo): path literal /gyms.
                Route::post('gyms', [\Innertia\Saas\Auth\Http\Controllers\CreateGymController::class, 'store']);
            });
        });
    }
}
