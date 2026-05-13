<?php

namespace Innertia\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Traits\HasApps;

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
    use HasApps;

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

    // ── Route model binding ───────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'key';
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
