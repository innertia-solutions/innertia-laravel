<?php

namespace Innertia\Telemetry\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Innertia\Telemetry\Models\TelemetryPresence;
use Innertia\Telemetry\TelemetryCollector;

class PresenceController extends Controller
{
    public function heartbeat(Request $request): Response
    {
        if (! config('innertia.telemetry.presence.enabled', true)) {
            return response()->noContent();
        }

        $data = $request->validate(['view' => 'nullable|string|max:255']);
        $collector = app(TelemetryCollector::class);

        TelemetryPresence::updateOrCreate(
            ['session_id' => $collector->sessionId()],
            [
                'user_id'      => auth()->id(),
                'tenant'       => $collector->tenant(),
                'view'         => $data['view'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->noContent();
    }
}
