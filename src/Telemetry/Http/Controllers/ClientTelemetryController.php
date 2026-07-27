<?php

namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\TelemetryCollector;

class ClientTelemetryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view'    => 'nullable|string|max:255',
            'action'  => 'nullable|string|max:255',
            'message' => 'required|string|max:2000',
            'context' => 'nullable|array',
        ]);

        $collector = app(TelemetryCollector::class);

        TelemetryEvent::create([
            'app'         => $collector->appName(),
            'session_id'  => $collector->sessionId(),
            'trace_id'    => $collector->traceId(),
            'type'        => 'client',
            'occurred_at' => now(),
            'payload'     => [
                'view'    => $data['view'] ?? null,
                'action'  => $data['action'] ?? null,
                'message' => $data['message'],
            ],
            'context'     => [
                'tenant'  => $collector->tenant(),
                'user_id' => auth()->id(),
                'client'  => $data['context'] ?? [],
            ],
        ]);

        return response()->json(['accepted' => true], 202);
    }
}
