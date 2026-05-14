<?php

namespace Innertia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Genera un NanoId por request, lo expone como X-Trace-Id en la respuesta
 * y lo inyecta en el contenedor para que el Monolog processor lo incluya en logs.
 */
class TraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Reutiliza si viene del cliente (útil para tracing distribuido)
        $traceId = $request->header('X-Trace-Id') ?: static::generate();

        app()->instance('trace_id', $traceId);

        $response = $next($request);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }

    /**
     * NanoId de 21 chars — mismo alphabet que el estándar nanoid/js.
     * Sin dependencias externas.
     */
    public static function generate(int $size = 21): string
    {
        $alphabet = 'useandom-26T198340PX75pxJACKVERYMINDBUSHWOLF_GQZbfghjklqvwyzrict';
        $id       = '';
        $bytes    = random_bytes($size);

        for ($i = 0; $i < $size; $i++) {
            $id .= $alphabet[ord($bytes[$i]) & 63];
        }

        return $id;
    }
}
