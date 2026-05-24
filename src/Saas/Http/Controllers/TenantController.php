<?php

namespace Innertia\Saas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController
{
    /**
     * GET /status  (Header: X-Tenant: {slug})
     *
     * Estado del tenant + features habilitadas + branding (info pública para boot SSR).
     * El middleware ResolveTenantFromHeader ya validó el tenant antes de llegar aquí.
     *
     * Response shape:
     *   {
     *     ok: true,
     *     tenant:   { id, key, name, status, isActive },
     *     features: { organizations, teams, twoFactor, oauth: ['google',...] },
     *     branding: { demo: { email, password } | null }
     *   }
     */
    public function status(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) return response()->json(['ok' => false], 404);

        $isActive = $tenant->isActive() || $tenant->isOnTrial();

        return response()->json([
            'ok'     => $isActive,
            'tenant' => [
                'id'       => $tenant->id,
                'key'      => $tenant->key,
                'name'     => $tenant->name ?? $tenant->key,
                'status'   => $tenant->status,
                'isActive' => $isActive,
            ],
            'features' => [
                'organizations' => \Innertia\Platform\Organizations\OrganizationsFeature::isActive(),
                'teams'         => \Innertia\Platform\Teams\TeamsFeature::isActive(),
                'twoFactor'     => (bool) ($tenant->configs['features']['twoFactor'] ?? false),
                'oauth'         => array_values($tenant->configs['oauthProviders'] ?? []),
            ],
            'branding' => [
                'demo' => $tenant->configs['demo'] ?? null,
            ],
        ]);
    }

    /**
     * DEPRECATED: GET /ping — mantiene shape antiguo para backward compat.
     * Será removido en una versión mayor. Usar /status en su lugar.
     */
    public function ping(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant) return response()->json(['ok' => false], 404);

        $isActive = $tenant->isActive() || $tenant->isOnTrial();

        return response()->json([
            'ok'     => $isActive,
            'tenant' => [
                'id'     => $tenant->id,
                'status' => $tenant->status,
                'config' => [
                    'oauthProviders' => $tenant->configs['oauthProviders'] ?? [],
                    'features'       => $tenant->configs['features'] ?? [],
                    'isActive'       => $isActive,
                    'demo'           => $tenant->configs['demo'] ?? null,
                ],
            ],
        ]);
    }

    private function resolveTenant(Request $request)
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $slug  = $request->header('X-Tenant');
        return $model::where('key', $slug)->first();
    }
}
