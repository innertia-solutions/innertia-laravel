<?php

namespace Innertia\Saas\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garantiza que hay un tenant activo en el contexto.
 *
 * Usar en rutas que exigen contexto de tenant (siempre después de
 * ResolveTenantFromHeader en la pila de middlewares).
 */
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Innertia::tenant()) {
            return response()->json(['message' => 'Tenant required.'], 401);
        }

        return $next($request);
    }
}
