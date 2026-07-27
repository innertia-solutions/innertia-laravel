<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryCase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lista casos de error agrupados por fingerprint.')]
#[IsReadOnly]
class ListCasesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $status = (string) $request->get('status', 'open');
        $limit  = (int) $request->get('limit', 25);

        $cases = TelemetryCase::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get(['id', 'title', 'exception_class', 'status', 'times_seen', 'last_seen', 'last_trace_id']);

        return Response::json($cases->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('open|resolved|ignored|all (default open)'),
            'limit'  => $schema->integer()->description('Máximo de casos (default 25)'),
        ];
    }
}
