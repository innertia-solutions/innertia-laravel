<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * UsageEvent — registro append-only de consumo de tokens por invocación.
 *
 * Solo tiene `created_at` (sin updated_at) → $timestamps = false.
 */
class UsageEvent extends Model
{
    use HasUuids;

    protected $table = 'ai_usage_events';

    public $timestamps = false;

    protected $fillable = [
        'provider_id',
        'model',
        'use',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens'      => 'integer',
        'created_at'        => 'datetime',
    ];
}
