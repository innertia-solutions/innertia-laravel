<?php

namespace Innertia\Telemetry\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryEvent extends Model
{
    protected $table = 'telemetry_events';

    protected $fillable = [
        'app',
        'session_id',
        'type',
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
