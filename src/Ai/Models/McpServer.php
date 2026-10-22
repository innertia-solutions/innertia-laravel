<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * McpServer — un servidor MCP externo que el módulo de IA puede invocar.
 *
 * `api_key` se cifra en la capa del modelo (cast encrypted).
 */
class McpServer extends Model
{
    use HasUuids;

    protected $table = 'ai_mcp_servers';

    protected $fillable = [
        'key',
        'name',
        'enabled',
        'api_key',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'api_key' => 'encrypted',
    ];
}
