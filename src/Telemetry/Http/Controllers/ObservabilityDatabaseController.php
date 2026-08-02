<?php

namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Telemetry\Health\DatabaseCapacity;
use Innertia\Telemetry\Health\SystemHealth;

class ObservabilityDatabaseController extends Controller
{
    public function show(): JsonResponse
    {
        $health = (new SystemHealth())->collect();

        return response()->json([
            'capacity' => (new DatabaseCapacity())->collect(),
            'disk'     => $health['disk'] ?? null,
            'queue'    => $health['queue'] ?? null,
        ]);
    }
}
