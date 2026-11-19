<?php

namespace Innertia\PublicApi\Models;

use Illuminate\Database\Eloquent\Model;

/** One request made with an integration key (method, path, status, ms). Never stores the key: only its prefix. */
class ApiRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'api_key_id', 'key_prefix', 'method', 'path', 'route', 'status', 'duration_ms', 'ip', 'trace_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function toApiArray(): array
    {
        return [
            'id'          => $this->id,
            'method'      => $this->method,
            'path'        => $this->path,
            'status'      => (int) $this->status,
            'duration_ms' => (int) $this->duration_ms,
            'ip'          => $this->ip,
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
