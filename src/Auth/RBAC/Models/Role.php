<?php

namespace Innertia\Auth\RBAC\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Facades\Permissions;
use Innertia\Platform\Traits\HasHistory;

/**
 * Role model.
 *
 * In SaaS mode roles are scoped per-tenant (tenant_id set at creation).
 * In app mode tenant_id is always null.
 *
 * Usage:
 *   $role = Role::create(['name' => 'admin']);
 *   $role->givePermissions('users.view', 'users.manage');
 *   $role->syncPermissions(['users.view']);
 *   $role->hasPermission('users.view');   // true|false
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $description
 * @property string|null $tenant_id
 */
class Role extends Model
{
    use HasUuids, HasHistory;

    protected $fillable = ['name', 'description', 'tenant_id'];

    // ── Static lookups ────────────────────────────────────────────────────────

    /**
     * Find a role by name, optionally scoped to a tenant (SaaS mode only).
     */
    public static function findByName(string $name, ?string $tenantId = null): ?static
    {
        $query = static::where('name', $name);

        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }

    /**
     * Find by name or throw NotFoundException.
     */
    public static function findByNameOrFail(string $name, ?string $tenantId = null): static
    {
        return static::findByName($name, $tenantId)
            ?? throw new NotFoundException("Role \"{$name}\" not found.");
    }

    /**
     * Create a role, enforcing uniqueness per name (+ tenant_id in SaaS mode).
     */
    public static function createUnique(string $name, ?string $description = null, ?string $tenantId = null): static
    {
        if (static::findByName($name, $tenantId)) {
            throw new ConflictException("A role with name \"{$name}\" already exists.");
        }

        $attributes = ['name' => $name, 'description' => $description];

        if (config('innertia.mode') === 'saas') {
            $attributes['tenant_id'] = $tenantId;
        }

        return static::create($attributes);
    }

    // ── Permission management ─────────────────────────────────────────────────

    /**
     * Add permissions to the role without removing existing ones.
     *
     * Accepts: string names, BackedEnum values, or Permission models.
     * Busts the cache for every user that has this role.
     */
    public function givePermissions(string|\BackedEnum|Permission ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);
        $this->permissions()->syncWithoutDetaching($ids);
        Permissions::flushRole($this);

        return $this;
    }

    /**
     * Replace the role's full permission set.
     * Busts the cache for every user that has this role.
     */
    public function syncPermissions(array $permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);
        $this->permissions()->sync($ids);
        Permissions::flushRole($this);

        return $this;
    }

    /**
     * Remove one or more permissions from the role.
     * Busts the cache for every user that has this role.
     */
    public function revokePermissions(string|\BackedEnum|Permission ...$permissions): static
    {
        $ids = $this->resolvePermissionIds($permissions);
        $this->permissions()->detach($ids);
        Permissions::flushRole($this);

        return $this;
    }

    /**
     * Check if the role has a named (app-level) permission.
     */
    public function hasPermission(string|\BackedEnum $permission): bool
    {
        $name = $permission instanceof \BackedEnum ? $permission->value : $permission;

        return $this->permissions()
            ->where('name', $name)
            ->exists();
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('auth.providers.users.model', \App\Models\User::class),
            'model_roles',
            'role_id',
            'model_id'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolvePermissionIds(array $permissions): array
    {
        return collect($permissions)
            ->map(function ($p) {
                if ($p instanceof Permission) {
                    return $p->id;
                }

                $name = $p instanceof \BackedEnum ? $p->value : $p;

                return Permission::findOrCreate($name)->id;
            })
            ->all();
    }
}
