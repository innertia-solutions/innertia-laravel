<?php

namespace Innertia\Platform\Organizations\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationsFeature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads `X-Organization: <slug>` and (optionally) `X-Consolidated: true`,
 * then populates OrganizationContext for the rest of the pipeline.
 *
 * Behaviour matrix:
 *   feature disabled       → pass through, no side effects.
 *   no header              → pass through, context remains empty.
 *   header + unknown slug  → 401 JSON.
 *   header + known slug    → current() = id, scope() = [id].
 *   + X-Consolidated:true  → current() = id, scope() = user->accessibleOrganizationIds().
 *
 * Pair with RequireOrganization to enforce presence on a route group.
 */
class ResolveOrganizationFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! OrganizationsFeature::isActive()) {
            return $next($request);
        }

        $slug = trim((string) $request->header('X-Organization', ''));

        if ($slug === '') {
            return $next($request);
        }

        /**
         * @var class-string<\Innertia\Platform\Contracts\OrganizationContract> $modelClass
         *
         * Defaults to the library's concrete model
         * (Innertia\Platform\Organizations\Models\Organization). Apps may
         * override this via config to point at their own subclass or any
         * class implementing OrganizationContract.
         */
        $modelClass = config(
            'innertia.organizations.model',
            \Innertia\Platform\Organizations\Models\Organization::class
        );
        if (! $modelClass || ! class_exists($modelClass)) {
            return response()->json([
                'message' => 'innertia.organizations.model is not configured.',
            ], 500);
        }

        if (! is_subclass_of($modelClass, \Innertia\Platform\Contracts\OrganizationContract::class)) {
            return response()->json([
                'message' => 'innertia.organizations.model must implement OrganizationContract.',
            ], 500);
        }

        $org = $modelClass::findByKey($slug);
        if (! $org) {
            return response()->json(['message' => 'Organization not found.'], 401);
        }

        $ctx = Innertia::organization();
        $ctx->set((int) $org->getKey());

        if (filter_var($request->header('X-Consolidated'), FILTER_VALIDATE_BOOL)) {
            $user = $request->user() ?? auth()->user();
            if ($user && method_exists($user, 'accessibleOrganizationIds')) {
                $ctx->setScope($user->accessibleOrganizationIds());
            }
        }

        return $next($request);
    }
}
