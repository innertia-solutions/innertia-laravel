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
            ],
        ]);
    }
}
