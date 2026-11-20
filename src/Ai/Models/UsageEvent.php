<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * UsageEvent — registro append-only de consumo de tokens por invocación.
 *
 * Solo tiene `created_at` (sin updated_at) → $timestamps = false.
 *
 * Desde la fase C también: tenant_id/organization_id (contexto activo al registrar), feature
 * (call_summary, transcription, assistant, tts…), units (input_tokens, output_tokens, audio_seconds,
 * tts_chars) y cost_usd (calculado con el precio vigente del modelo; null = modelo sin precio).
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
        'tenant_id',
        'organization_id',
        'feature',
        'units',
        'cost_usd',
        'created_at',
    ];

    protected $casts = [
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens'      => 'integer',
        'units'             => 'array',
        'cost_usd'          => 'float',
        'created_at'        => 'datetime',
    ];
}
