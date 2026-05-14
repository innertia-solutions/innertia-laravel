<?php

namespace Innertia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza Accept: application/json en todos los requests.
 *
 * Garantiza que la API siempre responda JSON:
 * - El exception handler activa su rama JSON en toda excepción
 * - Los middlewares de Laravel (auth, throttle) retornan JSON en lugar de redirect/HTML
 *
 * No aplica a responses de tipo BinaryFileResponse / StreamedResponse
 * (descargas y streaming) — esos ya tienen su propio Content-Type.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
