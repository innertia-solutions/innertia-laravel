<?php

namespace Innertia\Telemetry\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryCase extends Model
{
    protected $table = 'telemetry_cases';

    protected $fillable = [
        'fingerprint', 'title', 'exception_class', 'file', 'line',
        'message_normalized', 'status', 'times_seen', 'first_seen',
        'last_seen', 'last_trace_id', 'sample_trace_ids', 'notified_at',
        'snapshot', 'origin', 'category', 'tags',
    ];

    protected $casts = [
        'line'             => 'integer',
        'times_seen'       => 'integer',
        'first_seen'       => 'datetime',
        'last_seen'        => 'datetime',
        'notified_at'      => 'datetime',
        'sample_trace_ids' => 'array',
        'snapshot'         => 'array',
        'tags'             => 'array',
    ];
}
