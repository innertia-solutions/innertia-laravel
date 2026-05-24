<?php

namespace Innertia\Platform\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Contracts\OrganizationContract;
use Innertia\Platform\Traits\HasTenant;

/**
 * Organization model — second-level scoping inside a Tenant.
 *
 * id        — bigInteger auto-increment (PK interna, nunca expuesta en API).
 * tenant_id — FK a tenants (la org pertenece a un solo tenant).
 * key       — slug; identificador externo (header X-Organization), único por tenant.
 * name      — texto libre.
 * active    — flag de borrado lógico ligero (true por defecto).
 *
 * Extender en la app (patrón recomendado):
 *   class Organization extends \Innertia\Platform\Organizations\Models\Organization { ... }
 * Y configurar: config('innertia.organizations.model') = App\Models\Organization::class.
 *
 * Apps que prefieran reemplazar la clase por completo pueden hacerlo siempre
 * que implementen \Innertia\Platform\Contracts\OrganizationContract.
 */
class Organization extends Model implements OrganizationContract
{
    use HasTenant;

    protected $table = 'organizations';

    protected $fillable = [
        'tenant_id',
        'name',
        'key',
        'active',
    ];

    protected $casts = [
        'active' => 'bool',
    ];

    protected $attributes = [
        'active' => true,
    ];

    // ── Route model binding ──────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    // ── OrganizationContract ─────────────────────────────────────────────────

    public function getTenantId(): int|string|null
    {
        return $this->tenant_id;
    }

    public static function findByKey(string $key): ?static
    {
        return static::where('key', $key)->first();
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeByKey($query, string $key)
    {
        return $query->where('key', $key);
    }
}
