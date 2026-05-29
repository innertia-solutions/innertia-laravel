<?php

declare(strict_types=1);

namespace Innertia\Api\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Innertia\Platform\Traits\HasUuid;

class ApiKey extends Model
{
    use HasUuid;

    protected $table = 'api_keys';

    protected $fillable = [
        'organization_id',
        'name',
        'key',
        'key_prefix',
        'key_hint',
        'is_default',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = ['key'];

    protected $casts = [
        'is_default'   => 'bool',
        'revoked_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function touchLastUsed(): void
    {
        $this->updateQuietly(['last_used_at' => now()]);
    }

    // ── Static factory ────────────────────────────────────────────────────────

    /**
     * Generate attributes for a new API key (does NOT save).
     * Returns ['raw' => 'cog_...', 'attributes' => [...]]
     *
     * @return array{raw: string, attributes: array<string, mixed>}
     */
    public static function generate(
        string $organizationId,
        string $name = 'Default',
        bool   $isDefault = false,
    ): array {
        $prefix = config('innertia.api.key_prefix', 'cog_');
        $raw    = $prefix . Str::random(40);

        return [
            'raw' => $raw,
            'attributes' => [
                'organization_id' => $organizationId,
                'name'            => $name,
                'key'             => Hash::make($raw),
                'key_prefix'      => substr($raw, 0, 12),
                'key_hint'        => '...' . substr($raw, -4),
                'is_default'      => $isDefault,
            ],
        ];
    }

    /**
     * Find an active ApiKey by raw key value (prefix scan + hash check).
     * Returns null if not found or revoked.
     */
    public static function findByRawKey(string $raw): ?static
    {
        $prefix = config('innertia.api.key_prefix', 'cog_');

        if (! str_starts_with($raw, $prefix)) {
            return null;
        }

        $keyPrefix = substr($raw, 0, 12);

        $candidates = static::active()
            ->where('key_prefix', $keyPrefix)
            ->get();

        foreach ($candidates as $apiKey) {
            if (Hash::check($raw, $apiKey->key)) {
                return $apiKey;
            }
        }

        return null;
    }
}
