<?php

namespace Innertia\Platform\Organizations\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationsFeature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that an organization is active. Pair with ResolveOrganizationFromHeader.
 *
 *   Route::middleware(['tenant.require', 'organization.resolve', 'organization.require'])
 *        ->group(fn () => ...);
 *
 * No-op when the Organizations feature is inactive (disabled or in api mode).
 */
class RequireOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! OrganizationsFeature::isActive()) {
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
