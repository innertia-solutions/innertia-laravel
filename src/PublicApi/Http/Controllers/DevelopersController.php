<?php

namespace Innertia\PublicApi\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/** GET /developers — API reference page (Scalar from jsDelivr, pinned version) over the generated spec. */
class DevelopersController extends Controller
{
    public const SCALAR_URL = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.76.4/dist/browser/standalone.js';

    public function __invoke(): Response
    {
        $spec = request()->route()?->defaults['spec_url'] ?? url('api/v1/openapi.json');

        return response()->view('innertia::developers', [
            'title'     => (string) (config('innertia.public_api.title') ?: config('app.name', 'API') . ' API'),
            'specUrl'   => $spec,
            'scalarUrl' => (string) (config('innertia.public_api.scalar_url') ?: self::SCALAR_URL),
        ]);
    }
}
