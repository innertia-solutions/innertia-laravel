<?php

namespace Innertia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza Accept: application/json en los requests de API.
 *
 * Garantiza que la API siempre responda JSON:
 * - El exception handler activa su rama JSON en toda excepción
 * - Los middlewares de Laravel (auth, throttle) retornan JSON en lugar de redirect/HTML
 *
 * EXCEPCIÓN: las rutas de serving de archivos (view/download) NO se fuerzan, para
 * que un humano que abre un link compartible directo en el browser reciba una
 * página HTML de error (no encontrado / expirado) en vez de JSON crudo. El resto
 * de la API siempre se fuerza a JSON.
 *
 * No aplica a responses de tipo BinaryFileResponse / StreamedResponse
 * (descargas y streaming) — esos ya tienen su propio Content-Type.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // Las rutas de serving de archivos pueden abrirse directo en el browser
        // (links compartibles); NO forzamos JSON para que el exception handler
        // pueda negociar y devolver una página HTML de error según el Accept real.
        if (! $request->is('files/*/view', 'files/*/download')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
