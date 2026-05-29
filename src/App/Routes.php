<?php

namespace Innertia\App;

use Illuminate\Support\Facades\Route;
use Innertia\App\Http\Controllers\StatusController;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader;

/**
 * Rutas base del modo App (single-tenant) + stack privado compartido.
 * Sin resolución de tenant: el modo app no tiene multitenancy.
 */
class Routes
{
    /** GET /status — estado de la app (público). */
    public static function register(string $statusController = StatusController::class): void
    {
        Route::get('status', [$statusController, 'status']);
    }

    /**
     * Stack de middleware para rutas privadas app: autentica → resuelve organización
     * (no-op si el feature de orgs está deshabilitado).
     *
     * @return array<class-string>
     */
    public static function privateMiddleware(): array
    {
        return [
            Authenticate::class,
            ResolveOrganizationFromHeader::class,
        ];
    }
}
