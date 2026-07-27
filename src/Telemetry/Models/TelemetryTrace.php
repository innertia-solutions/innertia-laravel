<?php

namespace Innertia\Telemetry\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryTrace extends Model
{
    protected $table = 'telemetry_traces';

    protected $fillable = [
        'trace_id', 'session_id', 'user_id', 'tenant', 'method', 'route',
        'url', 'view', 'status', 'duration_ms', 'ip', 'user_agent',
        'payload', 'response_summary', 'occurred_at',
    ];

    protected $casts = [
        'payload'          => 'array',
        'response_summary' => 'array',
        'status'           => 'integer',
        'duration_ms'      => 'integer',
        'occurred_at'      => 'datetime',
    ];

    public function events()
    {
        return $this->hasMany(TelemetryEvent::class, 'trace_id', 'trace_id');
    }
}
