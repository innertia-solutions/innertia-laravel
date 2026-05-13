<?php

namespace Innertia\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Models\EntityPermission;
use Innertia\Models\Role;

/**
 * Add to any Eloquent model to enable entity-level access control.
 *
 * This trait is for row-level / resource-level access — not app-wide named permissions.
 * It answers the question: "can THIS user access THIS specific resource?"
 *
 *   class File extends Model {
 *       use HasEntityPermissions;
 *   }
 *
 * Granting access:
 *   $file->grantAccessTo($user)               // User → File
 *   $file->grantAccessTo($user, 'edit')        // User → File (specific action)
 *   $file->grantAccessToRoles('admin')         // Role → File
 *   $file->grantAccessToRoles('admin', 'edit') // Role → File (specific action)
 *   $file->grantAccessTo($project)             // Entity → File (cascade)
 *
 * Revoking:
 *   $file->revokeAccessFrom($user)
 *   $file->revokeAllEntityAccess()            // drop all grants on this entity
 *
 * Checking:
 *   $file->isAccessibleBy($user)
 *   $file->isAccessibleBy($user, 'edit')
 *
 * Low-level (via EntityPermission model directly):
 *   EntityPermission::grant($file, $user)
 *   EntityPermission::check($file, $user)
 *   EntityPermission::revoke($file, $user)
 */
trait HasEntityPermissions
{
    // ── Relationship ──────────────────────────────────────────────────────────

    /**
     * All entity-level grants on this model instance.
     */
    public function entityPermissions(): HasMany
    {
        return $this->hasMany(EntityPermission::class, 'entity_id')
            ->where('entity_type', static::class);
    }

    // ── Grant ─────────────────────────────────────────────────────────────────

    /**
     * Grant access to one or more grantables (User, Role, or any entity).
     *
     * $file->grantAccessTo($user)
     * $file->grantAccessTo($user, 'edit')
     * $file->grantAccessTo($role, $user2)          // multiple grantables, same action
     * $file->grantAccessTo($user, action: 'delete')
     */
    public function grantAccessTo(mixed ...$args): static
    {
        [$grantables, $action] = $this->parseGrantArgs($args, 'access');

        foreach ($grantables as $grantable) {
            EntityPermission::grant($this, $grantable, $action);
        }

        return $this;
    }

    /**
     * Grant access to roles by name.
     *
     * $file->grantAccessToRoles('admin', 'manager')
     * $file->grantAccessToRoles('admin', action: 'edit')
     */
    public function grantAccessToRoles(mixed ...$args): static
    {
        [$roleNames, $action] = $this->parseGrantArgs($args, 'access');

        $tenantId = (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;

        foreach ($roleNames as $name) {
            $role = Role::findByName((string) $name, $tenantId);

            if ($role) {
                EntityPermission::grant($this, $role, $action);
            }
        }

        return $this;
    }

    // ── Revoke ────────────────────────────────────────────────────────────────

    /**
     * Revoke access from one or more grantables.
     *
     * $file->revokeAccessFrom($user)
     * $file->revokeAccessFrom($user, action: 'edit')
     */
    public function revokeAccessFrom(mixed ...$args): static
    {
        [$grantables, $action] = $this->parseGrantArgs($args, 'access');

        foreach ($grantables as $grantable) {
            EntityPermission::revoke($this, $grantable, $action);
        }

        return $this;
    }

    /**
     * Remove ALL entity-level grants on this instance (all grantables, all actions).
     * Call this before/on delete to keep entity_permissions clean.
     */
    public function revokeAllEntityAccess(): void
    {
        EntityPermission::revokeAll($this);
    }

    // ── Check ─────────────────────────────────────────────────────────────────

    /**
     * Check if a user has the given action granted on this entity.
     *
     * Checks in order:
     *   1. Direct grant  — EntityPermission where grantable = $user
     *   2. Role-based    — EntityPermission where grantable = one of $user's roles
     *   3. Entity cascade— EntityPermission where grantable = $this->owner (if exists)
     *                       and owner itself is accessible by the user
     */
    public function isAccessibleBy(Authenticatable $user, string $action = 'access'): bool
    {
        $entityType = static::class;
        $entityId   = (string) $this->getKey();

        // 1 — Direct user grant
        $hasDirect = EntityPermission::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('grantable_type', get_class($user))
            ->where('grantable_id', (string) $user->getAuthIdentifier())
            ->where('action', $action)
            ->exists();

        if ($hasDirect) {
            return true;
        }

        // 2 — Role-based grant
        if (method_exists($user, 'roles')) {
            $roleIds = $user->roles()->pluck('id')->map(fn ($id) => (string) $id)->all();

            if (! empty($roleIds)) {
                $hasRoleGrant = EntityPermission::where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->where('grantable_type', Role::class)
                    ->whereIn('grantable_id', $roleIds)
                    ->where('action', $action)
                    ->exists();

                if ($hasRoleGrant) {
                    return true;
                }
            }
        }

        // 3 — Entity cascade: check if this entity itself is a grantable on another entity
        //     that the user can access. E.g. user can access Project → user can access Files of that Project.
        if (isset($this->owner_type, $this->owner_id) && $this->owner_type && $this->owner_id) {
            $ownerClass = $this->owner_type;
            $ownerId    = $this->owner_id;

            $ownerIsGrantable = EntityPermission::where('entity_type', $ownerClass)
                ->where('entity_id', $ownerId)
                ->where('grantable_type', get_class($user))
                ->where('grantable_id', (string) $user->getAuthIdentifier())
                ->where('action', $action)
                ->exists();

            if ($ownerIsGrantable) {
                return true;
            }
        }

        return false;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Parse variadic args: positional Models + optional named 'action'.
     *
     * grantAccessTo($user1, $user2)                  → [$user1, $user2], 'access'
     * grantAccessTo($user, action: 'edit')            → [$user], 'edit'
     * grantAccessToRoles('admin', 'manager')          → ['admin', 'manager'], 'access'
     * grantAccessToRoles('admin', action: 'delete')   → ['admin'], 'delete'
     */
    private function parseGrantArgs(array $args, string $defaultAction): array
    {
        $action    = $defaultAction;
        $grantables = [];

        foreach ($args as $key => $value) {
            if ($key === 'action') {
                $action = $value;
            } else {
                $grantables[] = $value;
            }
        }

        return [$grantables, $action];
    }
}
