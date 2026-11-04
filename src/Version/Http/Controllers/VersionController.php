<?php

namespace Innertia\Version\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Version\VersionInfo;

class VersionController extends Controller
{
    /** Público: identifica el despliegue. Sin dependencias. */
    public function show(VersionInfo $info): JsonResponse
    {
        return response()->json($info->public())->header('Cache-Control', 'no-store');
    }

    /** Detalle (montar SOLO dentro del grupo platform del producto). */
    public function details(VersionInfo $info): JsonResponse
    {
        return response()->json($info->details())->header('Cache-Control', 'no-store');
    }
}
