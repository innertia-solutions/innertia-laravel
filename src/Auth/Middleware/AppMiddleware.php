<?php

namespace Innertia\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to users who have access to the given app/context.
 *
 * Usage:
 *   Route::middleware('app:backoffice')->group(...)
 *   Route::middleware('app:backoffice,sales')  // any of these passes
 *
 * Requires HasApps on the User model.
 */
class AppMiddleware
{
    public function handle(Request $request, Closure $next, string ...$apps): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! method_exists($user, 'hasApp')) {
            abort(403, 'User model does not use the HasApps trait.');
        }

        foreach ($apps as $app) {
            if ($user->hasApp($app)) {
                return $next($request);
            }
        }

        abort(403, 'Access to this app is not allowed.');
    }
}
