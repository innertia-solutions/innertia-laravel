<?php

namespace Innertia\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Innertia\Models\Permission;
use Innertia\Models\Role;

/**
 * Add to any Eloquent model to enable entity-level access control.
 *
 * This trait allows granting/revoking access to a specific model instance
 * for individual users or roles — independently of app-level named permissions.
 *
 *   class File extends Model {
 *       use HasEntityPermissions;
 *   }
 *
 * Usage:
 *   $file->grantAccessTo($user1, $user2);
 *   $file->grantAccessToRoles('admin', 'manager');
 *   $file->revokeAccessFrom($user);
 *   $file->isEntityAccessibleBy($user);    // bool
 *   $file->revokeAllEntityAccess();        // drops the permission record entirely
 *
 * How it works:
 *   - A row in `permissions` is lazily created: name='access', entity_type=<class>, entity_id=<id>
 *   - Grants are rows in `model_permissions`: model_type=User::class, model_id=<uid>, permission_id=<pid>
 *   - Role grants: model_type=Role::class, model_id=<role_id>, permission_id=<pid>
 */
trait HasEntityPermissions
{
    // ── Grant ─────────────────────────────────────────────────────────────────

    /**
     * Grant access to specific users.
     */
    public function grantAccessTo(Authenticatable ...$users): static
    {
        $permission = Permission::forEntity($this);

        foreach ($users as $user) {
            DB::table('model_permissions')->insertOrIgnore([
                'model_type'    => get_class($user),
                'model_id'      => (string) $user->getAuthIdentifier(),
                'permission_id' => $permission->id,
            ]);
        }

        return $this;
    }

    /**
     * Grant access to roles by name.
     * Roles are resolved within the current tenant context.
     */
    public function grantAccessToRoles(string ...$roleNames): static
    {
        $permission = Permission::forEntity($this);
        $tenantId   = $this->currentEntityTenantId();

        foreach ($roleNames as $name) {
            $role = Role::findByName($name, $tenantId);

            if ($role) {
                DB::table('model_permissions')->insertOrIgnore([
                    'model_type'    => Role::class,
                    'model_id'      => (string) $role->id,
                    'permission_id' => $permission->id,
                ]);
            }
        }

        return $this;
    }

    // ── Revoke ────────────────────────────────────────────────────────────────

    /**
     * Revoke access from specific users.
     */
    public function revokeAccessFrom(Authenticatable ...$users): static
    {
        $permission = $this->entityPermission();

        if (! $permission) {
            return $this;
        }

        foreach ($users as $user) {
            DB::table('model_permissions')
                ->where('model_type', get_class($user))
                ->where('model_id', (string) $user->getAuthIdentifier())
                ->where('permission_id', $permission->id)
                ->delete();
        }

        return $this;
    }

    /**
     * Remove all entity-level access grants and the permission record itself.
     * Use when the entity is deleted or access needs a full reset.
     */
    public function revokeAllEntityAccess(): void
    {
        $permission = $this->entityPermission();

        if ($permission) {
            // model_permissions rows are cascade-deleted via FK
            $permission->delete();
        }
    }

    // ── Check ─────────────────────────────────────────────────────────────────

    /**
     * Check if a user has entity-level access to this model instance.
     *
     * Checks in order:
     *   1. Direct user grant (model_permissions where model = user)
     *   2. Role-based grant (model_permissions where model = one of user's roles)
     */
    public function isEntityAccessibleBy(Authenticatable $user): bool
    {
        $permission = $this->entityPermission();

        if (! $permission) {
            return false;
        }

        // 1 — Direct user grant
        $hasDirect = DB::table('model_permissions')
            ->where('model_type', get_class($user))
            ->where('model_id', (string) $user->getAuthIdentifier())
            ->where('permission_id', $permission->id)
            ->exists();

        if ($hasDirect) {
            return true;
        }

        // 2 — Role-based grant
        if (method_exists($user, 'roles')) {
            $roleIds = $user->roles()->pluck('id')->map(fn ($id) => (string) $id)->all();

            if (! empty($roleIds)) {
                return DB::table('model_permissions')
                    ->where('model_type', Role::class)
                    ->whereIn('model_id', $roleIds)
                    ->where('permission_id', $permission->id)
                    ->exists();
            }
        }

        return false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Retrieve the entity permission record (if it exists).
     * Returns null if no access grants have been set up yet.
     */
    public function entityPermission(): ?Permission
    {
        return Permission::where('entity_type', static::class)
            ->where('entity_id', (string) $this->getKey())
            ->first();
    }

    private function currentEntityTenantId(): ?string
    {
        return (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;
    }
}
