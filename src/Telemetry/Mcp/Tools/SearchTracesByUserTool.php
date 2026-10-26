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

#[Description('Busca los requests (traces) de un usuario en una ventana de días; opcionalmente solo los que tuvieron error.')]
#[IsReadOnly]
class SearchTracesByUserTool extends Tool
{
    public function handle(Request $request): Response
    {
        $userId = (string) $request->get('user_id');
        $days = (int) ($request->get('days') ?? 7);
        $onlyErrors = (bool) ($request->get('only_errors') ?? false);

        $traces = TelemetryTrace::query()
            ->where('user_id', $userId)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get();

        $errorTraceIds = TelemetryEvent::query()
            ->whereIn('trace_id', $traces->pluck('trace_id'))
            ->where('type', 'exception')
            ->pluck('trace_id')
            ->unique()
            ->flip();

        $items = $traces->map(function (TelemetryTrace $t) use ($errorTraceIds) {
            $hasError = isset($errorTraceIds[$t->trace_id]) || (int) $t->status >= 500;

            return [
                'trace_id'    => $t->trace_id,
                'method'      => $t->method,
                'route'       => $t->route,
                'status'      => $t->status,
                'occurred_at' => (string) $t->occurred_at,
                'has_error'   => $hasError,
            ];
        });

        if ($onlyErrors) {
            $items = $items->filter(fn (array $i) => $i['has_error'])->values();
        }

        return Response::json([
            'user_id' => $userId,
            'days'    => $days,
            'traces'  => $items->values()->toArray(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id'     => $schema->string()->description('id del usuario')->required(),
            'days'        => $schema->integer()->description('ventana en días (default 7)'),
            'only_errors' => $schema->boolean()->description('solo traces con error'),
        ];
    }
}
