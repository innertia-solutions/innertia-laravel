<?php

namespace Innertia\Saas;

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader;
use Innertia\Facades\Innertia;
use Innertia\Saas\Http\Controllers\TenantController;
use Innertia\Saas\Middleware\RequireTenant;
use Innertia\Saas\Middleware\ResolveTenantFromHeader;
use Innertia\Saas\Middleware\ValidateTenantMembership;

/**
 * Rutas base del modo SaaS + stack de middleware compartido.
 *
 * register() monta el endpoint público de estado del tenant (usado por el boot
 * SSR del frontend). Va dentro del grupo que resuelve el tenant.
 *
 * privateMiddleware() es el stack estándar para rutas privadas saas, reutilizado
 * por los registrars de dominio (\Innertia\Saas\{Backoffice,Organizations,...}).
 */
class Routes
{
    /** GET /status — tenant + features + branding (resuelve tenant del header). */
    public static function register(string $statusController = TenantController::class): void
    {
        Route::middleware(ResolveTenantFromHeader::class)->group(function () use ($statusController) {
            Route::get('status', [$statusController, 'status']);
        });
    }

    /**
     * Stack de middleware para rutas privadas saas:
     * resuelve tenant → autentica → exige tenant activo → resuelve organización.
     *
     * En modo `open` el guard de tenant es ValidateTenantMembership: además de
     * exigir un tenant resuelto, valida que el usuario autenticado pertenezca a él
     * (fila en user_contexts). En `saas` se mantiene RequireTenant (el subdominio /
     * header es el ancla de confianza y no hay selección libre de gym).
     *
     * @return array<class-string>
     */
    public static function privateMiddleware(): array
    {
        $tenantGate = Innertia::tenancyEnabled() && config('innertia.mode') === 'open'
            ? ValidateTenantMembership::class
            : RequireTenant::class;

        return [
            ResolveTenantFromHeader::class,
            Authenticate::class,
            $tenantGate,
            ResolveOrganizationFromHeader::class,
        ];
    }
}
