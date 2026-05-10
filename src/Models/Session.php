<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    protected $table = 'user_sessions';

    protected $fillable = [
        'user_id',
        'token_hash',
        'device_id',
        'ip',
        'browser',
        'expires_at',
    ];

    public function getFillable(): array
    {
        $fillable = parent::getFillable();

        if (config('innertia.mode') === 'saas') {
            array_unshift($fillable, 'tenant_id');
        }

        return $fillable;
    }

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
