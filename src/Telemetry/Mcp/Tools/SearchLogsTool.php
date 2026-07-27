<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryEvent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Busca eventos de log por nivel, texto o trace_id.')]
#[IsReadOnly]
class SearchLogsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $q = TelemetryEvent::query()->where('type', 'log');

        if ($level = $request->get('level')) {
            $q->where('payload->level', $level);
        }
        if ($text = $request->get('text')) {
            $q->where('payload->message', 'like', '%' . $text . '%');
        }
        if ($traceId = $request->get('trace_id')) {
            $q->where('trace_id', $traceId);
        }

        $rows = $q->orderByDesc('occurred_at')->limit((int) $request->get('limit', 50))->get();

        return Response::json($rows->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'level'    => $schema->string()->description('debug|info|warning|error|...'),
            'text'     => $schema->string()->description('Substring en el mensaje'),
            'trace_id' => $schema->string()->description('Filtra por un trace específico'),
            'limit'    => $schema->integer()->description('Máximo de filas (default 50)'),
        ];
    }
}
