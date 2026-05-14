<?php

namespace Innertia\Saas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController
{
    /**
     * GET /ping  (Header: X-Tenant: {slug})
     *
     * Ping liviano para que el frontend valide el tenant en SSR.
     * El middleware ResolveTenantFromHeader ya resolvió el tenant antes de llegar aquí.
     * Si el tenant no existe o está inactivo, ese middleware retorna 404/422 antes.
     *
     * Response: { ok: true, tenant: { id, status, config } }
     */
    public function ping(Request $request): JsonResponse
    {
        $model  = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $slug   = $request->header('X-Tenant');
        $tenant = $model::where('key', $slug)->first();

        if (!$tenant) {
            return response()->json(['ok' => false], 404);
        }

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
                ],
            ],
        ]);
    }
}
