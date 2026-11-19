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
        'created_by_key_id',
        'expires_at',
        // public API (integration keys)
        'prefix',
        'organization_id',
        'last_used_ip',
        'created_by',
        'rotated_to_id',
        'rotated_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
        'rotated_at'   => 'datetime',
    ];

    /** Integration keys of the public API (pk_live_… / pk_test_…), looked up by indexed prefix + SHA-256. */
    public const TYPE_INTEGRATION = 'integration';

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
        // revoked_at is not fillable: update() silently dropped it (keys were never revoked).
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    // ── Integration keys (public API) ─────────────────────────────────────────

    public function isIntegration(): bool
    {
        return $this->type === self::TYPE_INTEGRATION;
    }

    /** Scopes of an integration key (stored in `permissions`). */
    public function scopes(): array
    {
        return array_values(array_filter((array) ($this->permissions ?? []), 'is_string'));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes(), true);
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null                                  => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast()   => 'expired',
            $this->expires_at !== null && $this->expires_at->lte(now()->addDays(7)) => 'expiring',
            default                                                     => 'active',
        };
    }

    /** Serialized for the management API (never the hash). */
    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'prefix'          => $this->prefix,
            'hint'            => $this->key_hint,
            'scopes'          => $this->scopes(),
            'organization_id' => $this->organization_id !== null ? (string) $this->organization_id : null,
            'expires_at'      => $this->expires_at?->toIso8601String(),
            'last_used_at'    => $this->last_used_at?->toIso8601String(),
            'last_used_ip'    => $this->last_used_ip,
            'created_by'      => $this->created_by,
            'revoked_at'      => $this->revoked_at?->toIso8601String(),
            'rotated_to_id'   => $this->rotated_to_id,
            'status'          => $this->status(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
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
        $isSaas = \Innertia\Facades\Innertia::tenancyEnabled();
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
