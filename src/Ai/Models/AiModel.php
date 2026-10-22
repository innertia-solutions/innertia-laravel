<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AiModel — un modelo concreto ofrecido por un provider (p.ej. gpt-4o).
 */
class AiModel extends Model
{
    use HasUuids;

    protected $table = 'ai_models';

    protected $fillable = [
        'provider_id',
        'key',
        'name',
        'capabilities',
        'active',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'active'       => 'boolean',
    ];

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
