<?php

namespace Innertia\PublicApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\PublicApi\Docs\OpenApiGenerator;

/** GET {prefix}/openapi.json — public OpenAPI 3.1 document (no secrets: routes, schemas, events, scopes). */
class OpenApiController extends Controller
{
    public function __invoke(OpenApiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate(), 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
