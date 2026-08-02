<?php

namespace Innertia\Telemetry\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryEvent extends Model
{
    protected $table = 'telemetry_events';

    protected $fillable = [
        'app',
        'tenant_id',
        'session_id',
        'trace_id',
        'type',
        'channel',
        'occurred_at',
        'duration_ms',
        'payload',
        'context',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload'     => 'array',
        'context'     => 'array',
        'duration_ms' => 'integer',
    ];
}
