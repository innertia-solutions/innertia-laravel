<?php

namespace Innertia\Saas\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Auth\Models\UserContext;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo `open`: exige que el usuario autenticado pertenezca al tenant resuelto.
 *
 * El ancla de confianza en modo open ya no es el subdominio, sino la existencia
 * de una fila en `user_contexts` para (user, tenant). Sin esto, cualquier usuario
 * autenticado podría acceder a un gym ajeno spoofeando el header X-Tenant.
 *
 * Pila esperada: ResolveTenantFromHeader → Authenticate → ValidateTenantMembership.
 *
 * OJO: `user_contexts.tenant_id` almacena el ID numérico del tenant (getKey()),
 * NO su key — HasContexts::grantContext inyecta (string) Innertia::tenant()->getKey().
 */
class ValidateTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Innertia::tenant();
        abort_if($tenant === null, 401, 'Tenant no resuelto.');

        $user = $request->user();
        abort_if($user === null, 401, 'No autenticado.');

        $belongs = UserContext::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', (string) $tenant->getKey())
            ->exists();

        abort_unless($belongs, 403, 'No perteneces a este gym.');

        return $next($request);
    }
}
