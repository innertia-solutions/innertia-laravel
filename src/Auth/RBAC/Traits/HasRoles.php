<?php

namespace Innertia\Auth\RBAC\Traits;

use Illuminate\Support\Collection;
use Innertia\Exceptions\NotFoundException;
use Innertia\Facades\Innertia;
use Innertia\Facades\Permissions;
use Innertia\Auth\RBAC\Models\Permission;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Platform\Organizations\OrganizationsFeature;

/**
 * Add to your User model to enable role-based access control.
 *
 *   use Innertia\Auth\RBAC\Traits\HasRoles;
 *
 *   class User extends Authenticatable {
 *       use HasRoles;
 *   }
 *
 * Usage:
 *   $user->assignRole('admin');
 *   $user->removeRole('admin');
 *   $user->syncRoles(['admin', 'manager']);
 *   $user->hasRole('admin');                // bool
 *   $user->hasPermission('users.view');     // bool (role + direct grants)
 *   $user->hasPermission(UserPermissions::View); // enum support
 *   $user->getRoleNames();                  // Collection<string>
 */
trait HasRoles
{
    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * Roles assigned to this model.
     */
    public function roles(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        $relation = $this->morphToMany(
            Role::class,
            'model',
            'model_roles',
            'model_id',
            'role_id',
        );

        if (OrganizationsFeature::isActive()) {
            $relation->withPivot('organization_id');
        }

        return $relation;
    }

    /**
     * Direct (role-bypassing) permissions granted to this model.
     */
    public function directPermissions(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(
            Permission::class,
            'model',
            'model_permissions',
            'model_id',
            'permission_id',
        );
    }

    // ── Role management ───────────────────────────────────────────────────────

    /**
     * Assign a role by name or Role model.
     * In SaaS mode the role is resolved within the current tenant's context.
     */
    public function assignRole(string|Role $role, ?int $organizationId = null): void
    {
        $role = $this->resolveRole($role);

        $pivot = [];
        if (OrganizationsFeature::isActive()) {
            $orgId = $organizationId;
            if ($orgId === null) {
                $ctx   = \Innertia\Facades\Innertia::organization();
                $orgId = $ctx?->current();
            }
            $pivot['organization_id'] = $orgId;
        }

        $this->roles()->syncWithoutDetaching([$role->id => $pivot]);
        Permissions::flushUser($this);
    }

    /**
     * Remove a role by name or Role model.
     */
    public function removeRole(string|Role $role): void
    {
        $role = $this->resolveRole($role, throwIfMissing: false);

        if ($role) {
            $this->roles()->detach($role->id);
            Permissions::flushUser($this);
        }
    }

    /**
     * Replace the model's full role set.
     */
    public function syncRoles(array $roles): void
    {
        $tenantId = $this->currentTenantId();

        $ids = collect($roles)
            ->map(fn ($r) => $r instanceof Role ? $r->id : Role::findByName($r, $tenantId)?->id)
            ->filter()
            ->values()
            ->all();

        $this->roles()->sync($ids);
        Permissions::flushUser($this);
    }

    // ── Role checks ───────────────────────────────────────────────────────────

    /**
     * Check if the model has the given role.
     * Matches by role name within the current tenant context.
     */
    public function hasRole(string|Role $role, ?int $organizationId = null): bool
    {
        if ($role instanceof Role) {
            $q = $this->roles()->where('roles.id', $role->id);
            if (OrganizationsFeature::isActive()) {
                $q = $this->applyOrgPivotFilter($q, $organizationId);
            }
            return $q->exists();
        }

        $q = $this->roles()->where('roles.name', $role);

        // tenant_id solo existe en el schema tenant-scoped (saas/open); en app/api la columna no está.
        if (Innertia::tenancyEnabled()) {
            $tenantId = $this->currentTenantId();
            $q->where(function ($q) use ($tenantId) {
                $q->whereNull('roles.tenant_id');

                if ($tenantId !== null) {
                    $q->orWhere('roles.tenant_id', $tenantId);
                }
            });
        }

        if (OrganizationsFeature::isActive()) {
            $q = $this->applyOrgPivotFilter($q, $organizationId);
        }

        return $q->exists();
    }

    /**
     * Apply organization scoping at the model_roles pivot level.
     *
     * Resolution:
     *   - If $explicit is provided, match exactly that pivot.organization_id
     *     OR a globally-assigned role (pivot.organization_id IS NULL).
     *   - If $explicit is null, use OrganizationContext::current() with the
     *     same OR-NULL fallback. When context has no current(), only NULL
     *     pivots match.
     */
    private function applyOrgPivotFilter($query, ?int $explicit)
    {
        $orgId = $explicit;
        if ($orgId === null) {
            $ctx   = \Innertia\Facades\Innertia::organization();
            $orgId = $ctx?->current();
        }

        return $query->where(function ($q) use ($orgId) {
            $q->whereNull('model_roles.organization_id');
            if ($orgId !== null) {
                $q->orWhere('model_roles.organization_id', $orgId);
            }
        });
    }

    /**
     * Flat collection of role names assigned to this model.
     */
    public function getRoleNames(): Collection
    {
        return $this->roles()->pluck('name');
    }

    // ── Permission checks ─────────────────────────────────────────────────────

    /**
     * Check if the model has a named (app-level) permission.
     *
     * Delegates to PermissionsService so the result is cache-aware
     * and respects the optional permissions hierarchy from config.
     *
     * Accepts a plain string or any BackedEnum.
     */
    public function hasPermission(string|\BackedEnum $permission): bool
    {
        return Permissions::check($this, $permission);
    }

    /**
     * Grant a named permission directly to this model (bypasses roles).
     */
    public function givePermission(string|\BackedEnum $permission): void
    {
        $name = $permission instanceof \BackedEnum ? $permission->value : $permission;
        $p    = Permission::findOrCreate($name);
        $this->directPermissions()->syncWithoutDetaching([$p->id]);
        Permissions::flushUser($this);
    }

    /**
     * Revoke a direct named permission from this model.
     */
    public function revokePermission(string|\BackedEnum $permission): void
    {
        $name  = $permission instanceof \BackedEnum ? $permission->value : $permission;
        $query = Permission::where('name', $name);

        // tenant_id solo existe en el schema tenant-scoped (saas/open).
        if (Innertia::tenancyEnabled()) {
            $query->where('tenant_id', $this->currentTenantId());
        }

        $p = $query->first();

        if ($p) {
            $this->directPermissions()->detach($p->id);
            Permissions::flushUser($this);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveRole(string|Role $role, bool $throwIfMissing = true): ?Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        $tenantId = $this->currentTenantId();
        $found    = Role::findByName($role, $tenantId);

        if (! $found && $throwIfMissing) {
            throw new NotFoundException("Role \"{$role}\" not found.");
        }

        return $found;
    }

    private function currentTenantId(): ?string
    {
        return \Innertia\Facades\Innertia::tenant()
            ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
            : null;
    }
}
