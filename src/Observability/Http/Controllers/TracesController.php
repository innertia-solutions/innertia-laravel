<?php

namespace Innertia\Observability\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryTrace;

class TracesController extends Controller
{
    /** Resuelve un trace_id → request + eventos + logs + caso de error vinculado (si hay). */
    public function show(string $traceId): JsonResponse
    {
        $trace = TelemetryTrace::where('trace_id', $traceId)->first();

        if (! $trace) {
            return response()->json(['message' => 'Trace no encontrado.'], 404);
        }

        $events = $trace->events()->orderBy('occurred_at')->get();
        $logs   = $events->where('type', 'log')->values();

        // Caso vinculado: por last_trace_id o porque el trace_id está en sample_trace_ids.
        // sqlite-safe: no usar whereJsonContains (no portable); filtrar samples en PHP.
        $case = TelemetryCase::where('last_trace_id', $traceId)->first()
            ?? TelemetryCase::query()->get()
                ->first(fn (TelemetryCase $c) => in_array($traceId, (array) $c->sample_trace_ids, true));

        return response()->json([
            'trace'  => $trace,
            'events' => $events,
            'logs'   => $logs,
            'case'   => $case,
        ]);
    }
}
