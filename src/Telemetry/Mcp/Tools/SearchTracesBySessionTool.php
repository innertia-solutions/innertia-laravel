<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryTrace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Devuelve los requests (traces) de una sesión en orden cronológico — el flujo del usuario que pudo terminar en error.')]
#[IsReadOnly]
class SearchTracesBySessionTool extends Tool
{
    public function handle(Request $request): Response
    {
        $sessionId = (string) $request->get('session_id');
        $limit = (int) ($request->get('limit') ?? 50);

        $traces = TelemetryTrace::query()
            ->where('session_id', $sessionId)
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (TelemetryTrace $t) => [
                'trace_id'    => $t->trace_id,
                'method'      => $t->method,
                'route'       => $t->route,
                'status'      => $t->status,
                'occurred_at' => (string) $t->occurred_at,
            ]);

        return Response::json([
            'session_id' => $sessionId,
            'traces'     => $traces->toArray(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('id de la sesión')->required(),
            'limit'      => $schema->integer()->description('máximo de traces (default 50)'),
        ];
    }
}
