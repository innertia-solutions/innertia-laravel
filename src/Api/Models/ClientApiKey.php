<?php

namespace Innertia\Api\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Innertia\Platform\Traits\HasUuid;

class ClientApiKey extends Model
{
    use HasUuid;

    protected $fillable = [
        'client_id', 'name', 'key', 'key_prefix',
        'key_hint', 'permissions', 'expires_at', 'revoked_at', 'last_used_at',
    ];

    protected $hidden = ['key'];

    protected $casts = [
        'permissions'  => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        if ($this->revoked_at) return false;
        if ($this->expires_at?->isPast()) return false;
        return true;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    public function revoke(): void      { $this->update(['revoked_at' => now()]); }
    public function touchLastUsed(): void { $this->updateQuietly(['last_used_at' => now()]); }

    public static function generate(string $clientId, string $name, array $permissions = [], ?\Carbon\Carbon $expiresAt = null): array
    {
        $raw = 'cog_' . Str::random(40);

        return [
            'raw'        => $raw,
            'attributes' => [
                'client_id'   => $clientId,
                'name'        => $name,
                'key'         => Hash::make($raw),
                'key_prefix'  => substr($raw, 0, 12),
                'key_hint'    => '...' . substr($raw, -4),
                'permissions' => $permissions,
                'expires_at'  => $expiresAt,
            ],
        ];
    }

    public static function findByRawKey(string $raw): ?static
    {
        $prefix = config('innertia.api.key_prefix', 'cog_');

        if (! str_starts_with($raw, $prefix)) return null;

        $candidates = static::active()
            ->where('key_prefix', substr($raw, 0, 12))
            ->with('client')
            ->get();

        foreach ($candidates as $apiKey) {
            if (Hash::check($raw, $apiKey->key)) {
                return $apiKey;
            }
        }

        return null;
    }
}
