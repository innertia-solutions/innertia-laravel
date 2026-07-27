<?php

namespace Innertia\Telemetry\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Innertia\Telemetry\Health\SystemHealth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Salud mínima del server: memoria, cpu, disco, colas, php, db.')]
#[IsReadOnly]
class ServerHealthTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json((new SystemHealth())->collect());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
