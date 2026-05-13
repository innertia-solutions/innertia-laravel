<?php

namespace Innertia\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to users with at least one of the given permissions.
 *
 * Usage:
 *   Route::middleware('permission:users.view')
 *   Route::middleware('permission:users.view,users.manage')  // any passes
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! method_exists($user, 'hasPermission')) {
            abort(403, 'User model does not use the HasRoles trait.');
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
