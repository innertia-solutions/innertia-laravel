<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Models\TelemetryPresence;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Usuarios conectados ahora (heartbeat dentro del TTL de presencia).')]
#[IsReadOnly]
class WhoIsOnlineTool extends Tool
{
    public function handle(Request $request): Response
    {
        $ttl  = (int) config('innertia.telemetry.presence.ttl', 60);
        $rows = TelemetryPresence::where('last_seen_at', '>=', now()->subSeconds($ttl))
            ->orderByDesc('last_seen_at')
            ->get();

        return Response::json($rows->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
