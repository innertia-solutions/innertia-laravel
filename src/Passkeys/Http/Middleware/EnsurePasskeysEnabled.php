<?php

namespace Innertia\Passkeys\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Passkeys\Support\PasskeyConfig;

/** 404 en login y autogestión de passkeys cuando el interruptor de plataforma está apagado. */
class EnsurePasskeysEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (! PasskeyConfig::enabled()) {
            // El handler de la lib lo renderiza como {message: 'Not found.', error: 'not_found'}.
            abort(404, 'Not found.');
        }

        return $next($request);
    }
}
