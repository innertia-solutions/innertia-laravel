<?php

namespace Innertia\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Traits\HasContexts;

/**
 * Tenant model — single-DB multitenancy.
 *
 * id   — bigInteger auto-increment (PK interna, nunca expuesta en API)
 * key  — string slug; identificador externo (header X-Tenant)
 * name — texto libre; nombre del tenant
 *
 * Extender en la app:
 *   class Tenant extends \Innertia\Saas\Models\Tenant { ... }
 * Y configurar: config('innertia.saas.tenant_model') = App\Models\Tenant::class
 */
class Tenant extends Model
{
    use HasContexts;

    protected $fillable = [
        'key',
        'name',
        'status',
        'trial_ends_at',
        'configs',
        'data',
        'client_id',
        'environment',
        'billing_profile',
    ];

    protected $casts = [
        'configs'       => 'array',
        'data'          => 'array',
        'trial_ends_at' => 'datetime',
        'billing_profile' => 'array',
        'environment'   => \Innertia\Saas\Enums\TenantEnvironment::class,
    ];

    // ── Route model binding ───────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    // ── Client ────────────────────────────────────────────────────────────────

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function isTesting(): bool
    {
        return $this->environment === \Innertia\Saas\Enums\TenantEnvironment::Testing;
    }

    /** Client billing data with the space's own overrides on top. */
    public function billingData(): array
    {
        return array_merge(
            $this->client?->billingData() ?? [],
            array_intersect_key($this->billing_profile ?? [], array_flip(Client::BILLING_FIELDS)),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function findByKey(string $key): ?static
    {
        return static::where('key', $key)->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trial';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'inactive';
    }

    public function isTrialExpired(): bool
    {
        return $this->status === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }
}
