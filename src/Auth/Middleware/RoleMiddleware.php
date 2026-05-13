<?php

namespace Innertia\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to users with at least one of the given roles.
 *
 * Usage:
 *   Route::middleware('role:admin')
 *   Route::middleware('role:admin,manager')   // any of these roles passes
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! method_exists($user, 'hasRole')) {
            abort(403, 'User model does not use the HasRoles trait.');
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
