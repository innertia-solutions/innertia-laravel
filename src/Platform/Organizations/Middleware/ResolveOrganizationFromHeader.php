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
 *   tenancy sin tenant     → pass through (open pre-selección de tenant).
 *   header + unknown slug  → 401 JSON.
 *   org inactiva           → 403 organization_inactive.
 *   user sin membresía     → 403 organization_forbidden (user_contexts con la org o NULL;
 *                            is_platform_admin la saltea; sin user autenticado no valida).
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

        // Las orgs viven dentro de un tenant: con tenancy y sin tenant activo
        // (modo open antes de elegir tenant: /auth/me, /auth/my-gyms) no hay org
        // que resolver — se ignora el header en vez de responder 403/401.
        if (Innertia::tenancyEnabled() && Innertia::tenant() === null) {
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

        // FakeOrganization de los tests viejos no es Model → method_exists lo salta.
        // Sin cast boolean, sqlite/pgsql pueden devolver 0/'0'/false.
        if (method_exists($org, 'getAttribute') && in_array($org->getAttribute('active'), [false, 0, '0'], true)) {
            return response()->json([
                'message' => 'Esta organización está desactivada.',
                'error'   => 'organization_inactive',
            ], 403);
        }

        // Membresía: fila en user_contexts con esta org o NULL (= todas las del tenant).
        $user = $request->user() ?? auth()->user();
        if ($user && method_exists($user, 'canAccessOrganization')
            && ! ($user->is_platform_admin ?? false)
            && ! $user->canAccessOrganization((int) $org->getKey())) {
            return response()->json([
                'message' => 'No tienes acceso a esta organización.',
                'error'   => 'organization_forbidden',
            ], 403);
        }

        $ctx = Innertia::organization();
        $ctx->set((int) $org->getKey());

        if (filter_var($request->header('X-Consolidated'), FILTER_VALIDATE_BOOL)
            && $user && method_exists($user, 'accessibleOrganizationIds')) {
            $ctx->setScope($user->accessibleOrganizationIds());
        }

        return $next($request);
    }
}
