<?php

namespace Innertia\Ai\Mcp;

use Illuminate\Support\Str;
use Innertia\Ai\Models\McpServer;

/**
 * Alta/consulta de servidores MCP y generación de su API key. Las tools MCP las
 * aporta el producto; la librería solo gestiona registro, enabled y key.
 */
class McpRegistry
{
    public function register(string $key, string $name, bool $enabled = true): McpServer
    {
        return McpServer::updateOrCreate(
            ['key' => $key],
            ['name' => $name, 'enabled' => $enabled],
        );
    }

    public function get(string $key): ?McpServer
    {
        return McpServer::query()->where('key', $key)->first();
    }

    /** Genera y persiste una nueva API key (rota si ya existía). Devuelve el token en claro. */
    public function generateKey(string $key): string
    {
        $server = McpServer::query()->where('key', $key)->firstOrFail();

        $token = 'mcp_' . Str::random(48);
        $server->update(['api_key' => $token]);

        return $token;
    }
}
