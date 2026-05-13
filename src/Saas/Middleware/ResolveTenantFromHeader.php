<?php

namespace Innertia\Saas\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Exceptions\NotFoundException;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lee el header X-Tenant y activa el tenant correspondiente en el contexto.
 *
 * Si el header no está presente, pasa sin activar ningún tenant
 * (útil para rutas públicas que no requieren tenant).
 *
 * Si el header está presente pero el tenant no existe, devuelve 401.
 *
 * Usar junto con RequireTenant cuando se necesite garantizar que hay un tenant activo.
 */
class ResolveTenantFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Tenant');

        if ($key) {
            try {
                Innertia::activate($key);
            } catch (NotFoundException) {
                return response()->json(['message' => 'Tenant not found.'], 401);
            }
        }

        return $next($request);
    }
}
