<?php

namespace Innertia\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Innertia\Facades\Settings;

class UserOtp extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'action',
        'expires_at',
        'verified_at',
        'active',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
        'active'      => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isValid(): bool
    {
        return $this->active && ! $this->isExpired() && ! $this->isVerified();
    }

    public function markAsUsed(): void
    {
        $this->update([
            'verified_at' => now(),
            'active'      => false,
        ]);
    }

    public static function generate(Authenticatable $user, string $action, int $ttlMinutes = null): self
    {
        $ttl = $ttlMinutes ?? Settings::get('auth.otp.ttl', config('innertia.auth.otp.ttl', 10));

        static::where('user_id', $user->getAuthIdentifier())
            ->where('action', $action)
            ->where('active', true)
            ->update(['active' => false]);

        return static::create([
            'user_id'    => $user->getAuthIdentifier(),
            'code'       => str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'action'     => $action,
            'expires_at' => now()->addMinutes($ttl),
            'active'     => true,
        ]);
    }
}
