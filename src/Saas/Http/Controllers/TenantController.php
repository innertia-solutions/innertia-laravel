<?php

namespace Innertia\Saas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController
{
    /**
     * GET /tenant/validate?slug={key}
     *
     * Valida que el tenant exista y esté activo.
     * Usado por el middleware frontend para confirmar el subdomain antes de cargar la app.
     *
     * Response:
     *   { id, isActive, config: { oauthProviders, features, isActive } }
     */
    public function validate(Request $request): JsonResponse
    {
        $slug = $request->query('slug');

        if (!$slug) {
            return response()->json(['isActive' => false], 422);
        }

        $model  = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $tenant = $model::where('key', $slug)->first();

        if (!$tenant) {
            return response()->json(['isActive' => false], 404);
        }

        $isActive = $tenant->isActive() || $tenant->isOnTrial();

        return response()->json([
            'id'       => $tenant->id,
            'isActive' => $isActive,
            'config'   => [
                'oauthProviders' => $tenant->configs['oauthProviders'] ?? [],
                'features'       => $tenant->configs['features'] ?? [],
                'isActive'       => $isActive,
            ],
        ]);
    }
}
