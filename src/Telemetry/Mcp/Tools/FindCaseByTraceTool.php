<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryCase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Dado un trace_id, devuelve el/los casos de error vinculados a ese request (ir del trace al caso).')]
#[IsReadOnly]
class FindCaseByTraceTool extends Tool
{
    public function handle(Request $request): Response
    {
        $traceId = (string) $request->get('trace_id');

        // sqlite-safe: traer por last_trace_id + filtrar sample_trace_ids en PHP
        // (whereJsonContains no es portable a sqlite).
        $byLast = TelemetryCase::query()->where('last_trace_id', $traceId)->get();
        $bySample = TelemetryCase::query()->get()
            ->filter(fn (TelemetryCase $c) => in_array($traceId, (array) $c->sample_trace_ids, true));

        $cases = $byLast->concat($bySample)->unique('id')
            ->map(fn (TelemetryCase $c) => $c->only([
                'id', 'fingerprint', 'title', 'exception_class', 'status', 'times_seen', 'last_seen', 'last_trace_id',
            ]))
            ->values();

        return Response::json(['trace_id' => $traceId, 'cases' => $cases->toArray()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => $schema->string()->description('trace_id del request')->required(),
        ];
    }
}
