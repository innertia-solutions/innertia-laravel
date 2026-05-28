<?php

namespace Innertia\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to users who have access to the given context.
 *
 * Usage:
 *   Route::middleware('context:backoffice')->group(...)
 *   Route::middleware('context:backoffice,sales')  // any of these passes
 *
 * Requires HasContexts on the User model.
 */
class ContextMiddleware
{
    public function handle(Request $request, Closure $next, string ...$contexts): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! method_exists($user, 'hasContext')) {
            abort(403, 'User model does not use the HasContexts trait.');
        }

        foreach ($contexts as $context) {
            if ($user->hasContext($context)) {
                return $next($request);
            }
        }

        abort(403, 'Access to this context is not allowed.');
    }
}
