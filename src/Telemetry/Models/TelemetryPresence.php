<?php

namespace Innertia\Telemetry\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryPresence extends Model
{
    protected $table = 'telemetry_presence';

    protected $fillable = ['session_id', 'user_id', 'tenant', 'view', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];
}
