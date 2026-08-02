<?php

namespace Innertia\Telemetry\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryCaseOccurrence extends Model
{
    protected $table = 'telemetry_case_occurrences';

    public $timestamps = false;

    protected $fillable = ['case_id', 'day', 'count'];

    protected $casts = [
        'day'   => 'date',
        'count' => 'integer',
    ];
}
