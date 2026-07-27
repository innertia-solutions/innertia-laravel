<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Devuelve un request (trace) y todos sus eventos en orden cronológico.')]
#[IsReadOnly]
class GetTraceTool extends Tool
{
    public function handle(Request $request): Response
    {
        $traceId = (string) $request->get('trace_id');
        $trace   = TelemetryTrace::where('trace_id', $traceId)->first();

        if (! $trace) {
            return Response::json(['error' => 'trace not found']);
        }

        $events = TelemetryEvent::where('trace_id', $traceId)->orderBy('occurred_at')->get();

        return Response::json(['trace' => $trace->toArray(), 'events' => $events->toArray()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['trace_id' => $schema->string()->description('trace_id del request')->required()];
    }
}
