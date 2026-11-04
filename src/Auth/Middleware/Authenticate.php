<?php

namespace Innertia\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    public function handle(Request $request, Closure $next, string $guard = 'jwt'): Response
    {
        if (! auth($guard)->check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Cuenta de plataforma con clave temporal (default o `platform:password`):
        // hasta definir una nueva solo puede cambiarla, consultar su sesión o salir.
        $user = auth($guard)->user();
        if (($user->is_platform_admin ?? false) && ($user->force_password_change ?? false)
            && ! $request->is('*auth/password', '*auth/me', '*auth/logout')) {
            return response()->json([
                'message' => 'Debes definir una contraseña nueva antes de continuar.',
                'error'   => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
