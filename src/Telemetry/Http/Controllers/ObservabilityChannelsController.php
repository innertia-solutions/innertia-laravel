<?php

namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ObservabilityChannelsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'channels' => config('innertia.telemetry.channels', [
                ['key' => 'operational', 'label' => 'Logs operativos', 'icon' => 'IconFileText'],
            ]),
        ]);
    }
}
