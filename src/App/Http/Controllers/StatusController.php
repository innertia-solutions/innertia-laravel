<?php

namespace Innertia\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Estado de la app (modo single-tenant). Usado por el boot SSR del frontend.
 * No hay tenant: devuelve features activas + branding a nivel de app.
 */
class StatusController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'ok'     => true,
            'mode'   => 'app',
            'features' => [
                'organizations' => \Innertia\Platform\Organizations\OrganizationsFeature::isActive(),
                'teams'         => \Innertia\Platform\Teams\TeamsFeature::isActive(),
                'oauth'         => array_values((array) config('innertia.oauth', [])),
            ],
            'branding' => [
                'name' => config('app.name'),
                'demo' => $this->resolveDemo(),
            ],
        ]);
    }

    /**
     * Credenciales demo para pre-llenar el login (app mode). Setting global vía env:
     *   INNERTIA_DEMO_ENABLED, INNERTIA_DEMO_EMAIL, INNERTIA_DEMO_PASSWORD
     * Devuelve { email, password } solo si está habilitado y completo; si no, null.
     */
    private function resolveDemo(): ?array
    {
        $demo = (array) config('innertia.demo', []);

        if (empty($demo['enabled']) || empty($demo['email']) || empty($demo['password'])) {
            return null;
        }

        return [
            'email'    => $demo['email'],
            'password' => $demo['password'],
        ];
    }
}
