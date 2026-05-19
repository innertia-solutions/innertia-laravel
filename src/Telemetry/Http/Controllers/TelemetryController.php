<?php

namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Telemetry\Jobs\ProcessTelemetryJob;

class TelemetryController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app'                  => 'required|string|max:100',
            'session_id'           => 'nullable|string|max:100',
            'events'               => 'required|array|min:1|max:500',
            'events.*.type'        => 'required|string',
            'events.*.payload'     => 'required|array',
            'events.*.context'     => 'required|array',
            'events.*.occurred_at' => 'nullable|string',
            'events.*.duration_ms' => 'nullable|numeric',
        ]);

        ProcessTelemetryJob::dispatch($data)->onQueue(config('telemetry.queue', 'telemetry'));

        return response()->json(['accepted' => true, 'count' => count($data['events'])], 202);
    }
}
