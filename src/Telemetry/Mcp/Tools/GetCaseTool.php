<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Trae un caso con el trace completo de su última ocurrencia (eventos, payload, response, logs).')]
#[IsReadOnly]
class GetCaseTool extends Tool
{
    public function handle(Request $request): Response
    {
        $case = TelemetryCase::find((int) $request->get('case_id'));

        if (! $case) {
            return Response::json(['error' => 'case not found']);
        }

        $trace  = TelemetryTrace::where('trace_id', $case->last_trace_id)->first();
        $events = $trace
            ? TelemetryEvent::where('trace_id', $trace->trace_id)->orderBy('occurred_at')->get()
            : collect();

        return Response::json([
            'case'   => $case->toArray(),
            'trace'  => $trace?->toArray(),
            'events' => $events->toArray(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['case_id' => $schema->integer()->description('ID del caso')->required()];
    }
}
