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
        Route::middleware(ResolveTenantFromHeader::class)->group(function () use ($prefix) {
            // Público (resuelve tenant, sin auth)
            AuthRoutes::publicRoutes($prefix);

            // Autenticado (no exige tenant activo: se usa antes/después de elegir tenant)
            Route::middleware(Authenticate::class)->group(function () use ($prefix) {
                AuthRoutes::sessionRoutes($prefix);
            });
        });
    }
}
