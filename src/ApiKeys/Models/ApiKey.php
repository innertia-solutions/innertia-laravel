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
        string  $name,
        array   $permissions = [],
        ?string $tenantId = null,
        ?string $userId = null,
        ?\Carbon\Carbon $expiresAt = null,
    ): array {
        $isSaas = config('innertia.mode') === 'saas';
        $type   = $userId ? 'user' : ($isSaas ? 'tenant' : 'app');
        $prefix = match($type) {
            'user'   => 'inn_u_',
            'tenant' => 'inn_t_',
            default  => 'inn_a_',
        };
        $raw = $prefix . Str::random(40);

        $attributes = [
            'user_id'     => $userId,
            'name'        => $name,
            'type'        => $type,
            'key'         => Hash::make($raw),
            'key_hint'    => '...' . substr($raw, -4),
            'permissions' => $permissions,
            'expires_at'  => $expiresAt,
        ];

        if ($isSaas && $tenantId) {
            $attributes['tenant_id'] = $tenantId;
        }

        return ['raw' => $raw, 'attributes' => $attributes];
    }

    /**
     * Find an active ApiKey by raw key value (checks hash).
     * Returns null if not found, expired, or revoked.
     */
    public static function findByRawKey(string $raw): ?self
    {
        $type = match(true) {
            str_starts_with($raw, 'inn_t_') => 'tenant',
            str_starts_with($raw, 'inn_u_') => 'user',
            str_starts_with($raw, 'inn_a_') => 'app',
            default                         => null,
        };

        if (! $type) return null;

        $candidates = self::active()->where('type', $type)->get();

        foreach ($candidates as $apiKey) {
            if (Hash::check($raw, $apiKey->key)) {
                return $apiKey;
            }
        }

        return null;
    }
}
