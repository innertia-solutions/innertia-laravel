<?php

namespace Innertia\ApiKeys\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Innertia\Platform\Traits\HasUuid;

class ApiKey extends Model
{
    use HasUuid;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'type',
        'key',
        'key_hint',
        'permissions',
        'expires_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $hidden = ['key'];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function user()
    {
        $model = config('auth.providers.users.model');
        return $this->belongsTo($model, 'user_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        if ($this->revoked_at) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function touchLastUsed(): void
    {
        $this->updateQuietly(['last_used_at' => now()]);
    }

    // ── Static factory ─────────────────────────────────────────────────────────

    /**
     * Generate a new raw key + model attributes (does NOT save).
     * Returns ['raw' => '...', 'attributes' => [...]]
     */
    public static function generate(
        string $tenantId,
        string $name,
        array  $permissions = [],
        ?string $userId = null,
        ?\Carbon\Carbon $expiresAt = null,
    ): array {
        $type   = $userId ? 'user' : 'tenant';
        $prefix = $userId ? 'inn_u_' : 'inn_t_';
        $raw    = $prefix . Str::random(40);

        return [
            'raw'        => $raw,
            'attributes' => [
                'tenant_id'   => $tenantId,
                'user_id'     => $userId,
                'name'        => $name,
                'type'        => $type,
                'key'         => Hash::make($raw),
                'key_hint'    => '...' . substr($raw, -4),
                'permissions' => $permissions,
                'expires_at'  => $expiresAt,
            ],
        ];
    }

    /**
     * Find an active ApiKey by raw key value (checks hash).
     * Returns null if not found, expired, or revoked.
     */
    public static function findByRawKey(string $raw): ?self
    {
        // Derive tenant_id from prefix to narrow the lookup
        $type = str_starts_with($raw, 'inn_t_') ? 'tenant' : 'user';

        // We must check all active keys of this type — Hash::check is the only way
        // (no reverse lookup). Narrow with type to limit rows scanned.
        $candidates = self::active()->where('type', $type)->get();

        foreach ($candidates as $apiKey) {
            if (Hash::check($raw, $apiKey->key)) {
                return $apiKey;
            }
        }

        return null;
    }
}
