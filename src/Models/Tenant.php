<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Events\TenantUpdated;

/**
 * Base Tenant model for single-DB tenancy.
 *
 * id  — bigInteger auto-increment (internal PK, never exposed via API)
 * key — string slug used as external identifier (X-Tenant header / subdomain)
 *
 * Extend this in your app: class Tenant extends \Innertia\Models\Tenant { ... }
 * Then set config('innertia.saas.tenant_model') = App\Models\Tenant::class
 */
class Tenant extends Model implements TenantContract
{
    use HasDomains;

    protected $fillable = [
        'key',
        'name',
        'status',
        'trial_ends_at',
        'configs',
        'data',
    ];

    protected $casts = [
        'configs'       => 'array',
        'data'          => 'array',
        'trial_ends_at' => 'datetime',
    ];

    protected $dispatchesEvents = [
        'created' => TenantCreated::class,
        'updated' => TenantUpdated::class,
        'deleted' => TenantDeleted::class,
    ];

    /* ── TenantContract ── */

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey()
    {
        return $this->getKey();
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function getInternal(string $key)
    {
        return $this->getAttribute($key);
    }

    public function setInternal(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function run(callable $callback)
    {
        return tenancy()->run($this, $callback);
    }

    /* ── Helpers ── */

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
