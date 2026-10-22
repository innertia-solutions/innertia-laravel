<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AiAssignment — mapea un uso lógico (chat/vision/embedding/assistant) a un
 * provider + modelo concretos.
 */
class AiAssignment extends Model
{
    use HasUuids;

    protected $table = 'ai_assignments';

    protected $fillable = [
        'use',
        'provider_id',
        'model',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
