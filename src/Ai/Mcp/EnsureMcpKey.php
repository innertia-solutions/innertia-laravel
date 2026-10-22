<?php

namespace Innertia\Ai\Mcp;

use Closure;
use Illuminate\Http\Request;
use Innertia\Ai\Models\McpServer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware genérico de autenticación MCP: valida el header
 * `Authorization: Bearer <token>` contra `ai_mcp_servers.api_key` del server
 * identificado por su `key` (pasada como parámetro del middleware).
 *
 * Uso: ->middleware(EnsureMcpKey::class . ':question-bank')
 *   404 si el server no existe o está deshabilitado
 *   503 si el server existe pero no tiene API key generada
 *   401 si el Bearer falta o no coincide
 */
class EnsureMcpKey
{
    public function handle(Request $request, Closure $next, string $serverKey): Response
    {
        $server = McpServer::query()->where('key', $serverKey)->first();

        if (! $server || ! $server->enabled) {
            return response()->json(['message' => 'MCP server no disponible.'], 404);
        }

        if (! filled($server->api_key)) {
            return response()->json(['message' => 'MCP no configurado: generá la API key.'], 503);
        }

        $provided = (string) $request->bearerToken();
        if ($provided === '' || ! hash_equals((string) $server->api_key, $provided)) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}
