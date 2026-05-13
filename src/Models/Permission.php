<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Unified permission record.
 *
 * Named permission (entity_type = null, entity_id = null):
 *   Permission::findOrCreate('users.view')
 *
 * Entity-level permission (grants access to a specific model instance):
 *   Permission::findOrCreate('access', File::class, $file->id)
 *   Permission::forEntity($file)          // shorthand, name defaults to 'access'
 *   Permission::forEntity($file, 'edit')  // custom action name
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property string|null $description
 */
class Permission extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'entity_type', 'entity_id', 'description'];

    // ── Static factories ──────────────────────────────────────────────────────

    /**
     * Find an existing permission or create it.
     *
     * For named permissions:  findOrCreate('users.view')
     * For entity permissions: findOrCreate('access', File::class, $id)
     */
    public static function findOrCreate(
        string  $name,
        ?string $entityType = null,
        ?string $entityId   = null,
    ): static {
        return static::firstOrCreate([
            'name'        => $name,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ]);
    }

    /**
     * Get (or create) the entity-level permission for a model instance.
     *
     * Usage: Permission::forEntity($file)
     *        Permission::forEntity($document, 'edit')
     */
    public static function forEntity(Model $entity, string $name = 'access'): static
    {
        return static::findOrCreate($name, get_class($entity), (string) $entity->getKey());
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Only named (app-level) permissions. */
    public function scopeNamed(Builder $query): Builder
    {
        return $query->whereNull('entity_type');
    }

    /** Only entity-level permissions for a given model class. */
    public function scopeForEntityType(Builder $query, string $type): Builder
    {
        return $query->where('entity_type', $type);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
