<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AiProvider — un proveedor de IA (OpenAI, MiniMax, openai-compatible, fake).
 *
 * `api_key` se cifra en la capa del modelo (cast encrypted). Config global
 * de producto — NO tenant-scoped en v1.
 */
class AiProvider extends Model
{
    use HasUuids;

    protected $table = 'ai_providers';

    protected $fillable = [
        'key',
        'name',
        'type',
        'base_url',
        'api_key',
        'active',
        'meta',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'active'  => 'boolean',
        'meta'    => 'array',
    ];

    public function models()
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }

    public function assignments()
    {
        return $this->hasMany(AiAssignment::class, 'provider_id');
    }
}
