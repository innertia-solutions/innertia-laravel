<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryEvent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Correos enviados registrados por la telemetría.')]
#[IsReadOnly]
class EmailLogTool extends Tool
{
    public function handle(Request $request): Response
    {
        $rows = TelemetryEvent::where('type', 'mail')
            ->orderByDesc('occurred_at')
            ->limit((int) $request->get('limit', 50))
            ->get();

        return Response::json($rows->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->description('Máximo de filas (default 50)')];
    }
}
