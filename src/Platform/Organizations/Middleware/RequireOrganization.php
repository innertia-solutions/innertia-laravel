<?php

namespace Innertia\Platform\Organizations\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that an organization is active. Pair with ResolveOrganizationFromHeader.
 *
 *   Route::middleware(['tenant.require', 'organization.resolve', 'organization.require'])
 *        ->group(fn () => ...);
 *
 * No-op when config('innertia.organizations.enabled') is false.
 */
class RequireOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('innertia.organizations.enabled')) {
            return $next($request);
        }

        $ctx = Innertia::organization();
        if (! $ctx || $ctx->current() === null) {
            return response()->json([
                'message' => 'An organization is required for this request (set header X-Organization).',
            ], 400);
        }

        return $next($request);
    }
}
