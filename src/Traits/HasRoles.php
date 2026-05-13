<?php

namespace Innertia\Traits;

use Illuminate\Support\Collection;
use Innertia\Exceptions\NotFoundException;
use Innertia\Facades\Permissions;
use Innertia\Models\Permission;
use Innertia\Models\Role;

/**
 * Add to your User model to enable role-based access control.
 *
 *   use Innertia\Traits\HasRoles;
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
        return $this->morphToMany(
            Role::class,
            'model',
            'model_roles',
            'model_id',
            'role_id',
        );
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
    public function assignRole(string|Role $role): void
    {
        $role = $this->resolveRole($role);
        $this->roles()->syncWithoutDetaching([$role->id]);
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
    public function hasRole(string|Role $role): bool
    {
        if ($role instanceof Role) {
            return $this->roles()->where('roles.id', $role->id)->exists();
        }

        $tenantId = $this->currentTenantId();

        return $this->roles()
            ->where('roles.name', $role)
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('roles.tenant_id');

                if ($tenantId !== null) {
                    $q->orWhere('roles.tenant_id', $tenantId);
                }
            })
            ->exists();
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
        $name     = $permission instanceof \BackedEnum ? $permission->value : $permission;
        $tenantId = $this->currentTenantId();
        $p        = Permission::where('name', $name)->where('tenant_id', $tenantId)->first();

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
        return (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;
    }
}
