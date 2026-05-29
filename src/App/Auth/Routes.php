<?php

namespace Innertia\App\Auth;

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Auth\Routes as AuthRoutes;

/**
 * Rutas de autenticación para modo App (single-tenant) — agnósticas de contexto.
 * Sin middleware de tenant: público directo + autenticado con Authenticate.
 */
class Routes
{
    public static function register(string $prefix = 'auth'): void
    {
        // Público
        AuthRoutes::publicRoutes($prefix);

        // Autenticado
        Route::middleware(Authenticate::class)->group(function () use ($prefix) {
            AuthRoutes::sessionRoutes($prefix);
        });
    }
}
