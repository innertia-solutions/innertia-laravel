<?php

namespace Innertia\Auth\RBAC\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Entity-level access grant.
 *
 * Grants a specific grantable (User, Role, or another entity) the ability to
 * perform an action on a specific entity instance.
 *
 * This is separate from named app permissions (RBAC). Use this when you need
 * row-level / resource-level access control.
 *
 * Schema:
 *   entity_type + entity_id   — what is being accessed  (e.g. File, Document)
 *   grantable_type + grantable_id — who gets access (User, Role, another entity)
 *   action                    — what they can do (default: 'access')
 *
 * Examples:
 *   User  → File           $file->grantAccessTo($user)
 *   Role  → File           $file->grantAccessToRoles('admin')
 *   Entity → Entity        $invoice->grantAccessTo($project)  // cascade
 *
 * The HasEntityPermissions trait provides the high-level API on any model.
 *
 * @property string      $id
 * @property string|null $tenant_id
 * @property string      $entity_type
 * @property string      $entity_id
 * @property string      $grantable_type
 * @property string      $grantable_id
 * @property string      $action
 * @property \Carbon\Carbon $created_at
 */
class EntityPermission extends Model
{
    use HasUuids;

    public const UPDATED_AT = null; // immutable — no updated_at column

    protected $fillable = [
        'tenant_id',
        'entity_type',
        'entity_id',
        'grantable_type',
        'grantable_id',
        'action',
    ];

    // ── Static helpers ────────────────────────────────────────────────────────

    /**
     * Grant access. Silently ignores duplicate grants (insertOrIgnore).
     */
    public static function grant(
        Model  $entity,
        Model  $grantable,
        string $action    = 'access',
        ?string $tenantId = null,
    ): static {
        $tenantId ??= (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;

        return static::firstOrCreate([
            'entity_type'    => get_class($entity),
            'entity_id'      => (string) $entity->getKey(),
            'grantable_type' => get_class($grantable),
            'grantable_id'   => (string) $grantable->getKey(),
            'action'         => $action,
        ], [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Revoke a specific grant.
     */
    public static function revoke(
        Model  $entity,
        Model  $grantable,
        string $action = 'access',
    ): void {
        static::where('entity_type', get_class($entity))
            ->where('entity_id', (string) $entity->getKey())
            ->where('grantable_type', get_class($grantable))
            ->where('grantable_id', (string) $grantable->getKey())
            ->where('action', $action)
            ->delete();
    }

    /**
     * Revoke all grants on a specific entity (all grantables, all actions).
     * Use when the entity is being deleted.
     */
    public static function revokeAll(Model $entity): void
    {
        static::where('entity_type', get_class($entity))
            ->where('entity_id', (string) $entity->getKey())
            ->delete();
    }

    /**
     * Check if grantable has access to the entity.
     */
    public static function check(
        Model  $entity,
        Model  $grantable,
        string $action = 'access',
    ): bool {
        return static::where('entity_type', get_class($entity))
            ->where('entity_id', (string) $entity->getKey())
            ->where('grantable_type', get_class($grantable))
            ->where('grantable_id', (string) $grantable->getKey())
            ->where('action', $action)
            ->exists();
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /** The entity being accessed. */
    public function entity(): MorphTo
    {
        return $this->morphTo('entity');
    }

    /** Who has access. */
    public function grantable(): MorphTo
    {
        return $this->morphTo('grantable');
    }
}
