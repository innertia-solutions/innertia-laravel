<?php

namespace Innertia\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'action',
        'expires_at',
        'used_at',
        'active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'active'     => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function isValid(): bool
    {
        return $this->active
            && $this->used_at === null
            && $this->expires_at->isFuture();
    }

    public function markAsUsed(): void
    {
        $this->update([
            'used_at' => now(),
            'active'  => false,
        ]);
    }

    public static function generate(Authenticatable $user, string $action, int $ttlMinutes = 60): self
    {
        static::where('user_id', $user->getAuthIdentifier())
            ->where('action', $action)
            ->where('active', true)
            ->update(['active' => false]);

        return static::create([
            'user_id'    => $user->getAuthIdentifier(),
            'token'      => Str::random(64),
            'action'     => $action,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'active'     => true,
        ]);
    }
}
