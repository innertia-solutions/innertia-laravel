<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Auth\Models\UserContext;

/**
 * Lista los tenants (gyms) a los que pertenece el usuario autenticado,
 * SIN tenant activo. Se usa tras el login global para rutear:
 *   0 gyms → onboarding, 1 → entrar directo, 2+ → picker.
 *
 * user_contexts.tenant_id almacena el ID NUMÉRICO del tenant (getKey())
 * como string, no su key. Agrupamos por ese id, cargamos los Tenant por
 * id y devolvemos su `key` (nunca el id interno) al cliente.
 */
class MyTenantsController
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();
        $tenantModel = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);

        $rows = UserContext::query()
            ->where('user_id', $userId)
            ->get(['context', 'tenant_id'])
            ->groupBy('tenant_id'); // keyed by NUMERIC tenant id (string)

        $tenants = $tenantModel::query()
            ->whereIn('id', $rows->keys()->all())   // load by numeric id
            ->get()
            ->keyBy(fn ($t) => (string) $t->getKey());

        $gyms = $rows->map(function ($ctxs, $tenantId) use ($tenants) {
            $t = $tenants->get((string) $tenantId);
            if (! $t) {
                return null;
            }

            return [
                'key'      => $t->key,
                'name'     => $t->name,
                'status'   => $t->status,
                'contexts' => $ctxs->pluck('context')->unique()->values()->all(),
            ];
        })->filter()->values();

        return response()->json(['gyms' => $gyms]);
    }
}
