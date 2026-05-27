<?php

namespace Innertia\Files\Directories\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Platform\Traits\HasEntityPermissions;
use Innertia\Platform\Traits\HasTenant;

class Directory extends Model
{
    use HasUuids;
    use HasTenant;
    use SoftDeletes;
    use HasEntityPermissions;

    protected $table = 'directories';

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'path',
        'depth',
        'name',
        'name_normalized',
        'owner_type',
        'owner_id',
        'created_by',
        'trash_group_id',
    ];

    protected $casts = [
        'depth' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(\Innertia\Files\Models\File::class, 'directory_id');
    }

    // ── Tree navigation ──────────────────────────────────────────────────────

    /** Returns a query of all descendants (not the model itself). */
    public function descendants(): Builder
    {
        return static::query()
            ->where('path', 'like', $this->path . '%')
            ->where('id', '!=', $this->id);
    }

    /** Returns the ordered ancestors (root → immediate parent). */
    public function ancestors(): Collection
    {
        $ids = $this->ancestorIds();
        if (empty($ids)) {
            return collect();
        }

        return static::query()
            ->whereIn('id', $ids)
            ->withTrashed()
            ->get()
            ->sortBy(fn ($d) => array_search($d->id, $ids))
            ->values();
    }

    /** Breadcrumbs = ancestors + self, root → self. */
    public function breadcrumbs(): array
    {
        return $this->ancestors()
            ->concat([$this])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            ->all();
    }

    public function isDescendantOf(self $other): bool
    {
        if ($this->id === $other->id) {
            return false;
        }
        return str_starts_with($this->path, $other->path);
    }

    public function isAncestorOf(self $other): bool
    {
        return $other->isDescendantOf($this);
    }

    /** Extracts UUIDs from path, excluding self. */
    private function ancestorIds(): array
    {
        $parts = array_values(array_filter(explode('/', $this->path)));
        // Path ends with self's id — strip it
        array_pop($parts);
        return $parts;
    }

    // ── Query scopes ─────────────────────────────────────────────────────────

    public function scopeRoots(Builder $q, ?Model $owner = null): Builder
    {
        $q->whereNull('parent_id');

        if ($owner !== null) {
            $q->where('owner_type', $owner::class)
              ->where('owner_id', $owner->getKey());
        }

        return $q;
    }

    public function scopeInOwner(Builder $q, Model $owner): Builder
    {
        return $q->where('owner_type', $owner::class)
                 ->where('owner_id', $owner->getKey());
    }

    public function scopeDescendantsOf(Builder $q, self $dir): Builder
    {
        return $q->where('path', 'like', $dir->path . '%')
                 ->where('id', '!=', $dir->id);
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Check if a user has access to this directory.
     *
     * Returns true if the user has ANY grant on this directory or any ancestor
     * directory (inherited access via materialized path). The $action parameter
     * is intentionally ignored — Drive-style OR logic means any grant level
     * (view/edit/manage) is treated as sufficient for access. The most permissive
     * grant wins.
     *
     * @param Authenticatable $user
     * @param string $action Accepted for interface conformance; not used in evaluation.
     */
    public function isAccessibleBy(Authenticatable $user, string $action = 'access'): bool
    {
        // Direct grant on this directory (any action — see docblock)
        $directGrant = EntityPermission::where('entity_type', static::class)
            ->where('entity_id', (string) $this->getKey())
            ->where('grantable_type', get_class($user))
            ->where('grantable_id', (string) $user->getAuthIdentifier())
            ->exists();

        if ($directGrant) {
            return true;
        }

        // Inherited grant via ancestor directory
        $ancestorIds = $this->ancestorIds();
        if (empty($ancestorIds)) {
            return false;
        }

        return EntityPermission::where('entity_type', static::class)
            ->whereIn('entity_id', $ancestorIds)
            ->where('grantable_type', get_class($user))
            ->where('grantable_id', (string) $user->getAuthIdentifier())
            ->exists();
    }

    /**
     * Query scope: return only directories accessible by the given user.
     *
     * A directory is accessible if:
     *   - The user has a direct grant on it, OR
     *   - The user has a grant on one of its ancestor directories.
     */
    public function scopeAccessibleBy(Builder $q, Authenticatable $user): Builder
    {
        $userClass = get_class($user);
        $userId    = (string) $user->getAuthIdentifier();
        $dirClass  = static::class;

        return $q->where(function ($q) use ($userClass, $userId, $dirClass) {
            // Direct grant on this directory
            $q->whereExists(function ($sub) use ($userClass, $userId, $dirClass) {
                $sub->from('entity_permissions')
                    ->where('entity_type', $dirClass)
                    ->whereColumn('entity_id', 'directories.id')
                    ->where('grantable_type', $userClass)
                    ->where('grantable_id', $userId);
            });
            // OR: grant on any ancestor directory (path inheritance)
            $q->orWhereExists(function ($sub) use ($userClass, $userId, $dirClass) {
                $sub->from('entity_permissions as ep')
                    ->join('directories as ancestor', 'ancestor.id', '=', 'ep.entity_id')
                    ->where('ep.entity_type', $dirClass)
                    ->where('ep.grantable_type', $userClass)
                    ->where('ep.grantable_id', $userId)
                    ->whereRaw("directories.path LIKE (ancestor.path || '%')")
                    ->whereRaw('ancestor.id != directories.id');
            });
        });
    }

    // ── Static factories ─────────────────────────────────────────────────────

    /**
     * Factory method: creates a directory via CreateDirectory use case.
     *
     * @param  self|null  $parent   Parent directory (null for root)
     * @param  string     $name     Directory name
     * @param  \Illuminate\Database\Eloquent\Model|null  $owner  Optional owner model
     * @return self
     */
    public static function createIn(?self $parent, string $name, ?\Illuminate\Database\Eloquent\Model $owner = null): self
    {
        return (new \Innertia\Files\Directories\UseCases\CreateDirectory(
            parent: $parent,
            name:   $name,
            owner:  $owner,
        ))->execute();
    }

    // ── Instance method delegates ─────────────────────────────────────────────

    public function rename(string $newName): self
    {
        return (new \Innertia\Files\Directories\UseCases\RenameDirectory($this, $newName))->execute();
    }

    public function moveTo(self $newParent): self
    {
        return (new \Innertia\Files\Directories\UseCases\MoveDirectory($this, $newParent))->execute();
    }

    public function moveToRoot(): self
    {
        return (new \Innertia\Files\Directories\UseCases\MoveDirectory($this, null))->execute();
    }

    public function trash(): self
    {
        return (new \Innertia\Files\Directories\UseCases\TrashDirectory($this))->execute();
    }

    /**
     * Wrapper to disambiguate from Laravel's SoftDeletes::restore() which we keep available.
     * Restores the entire trash group this directory belongs to.
     */
    public function restoreFromTrash(?self $relocateParent = null): self
    {
        return (new \Innertia\Files\Directories\UseCases\RestoreDirectory($this, $relocateParent))->execute();
    }
}
