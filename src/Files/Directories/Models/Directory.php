<?php

namespace Innertia\Files\Directories\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Innertia\Platform\Traits\HasTenant;

class Directory extends Model
{
    use HasUuids;
    use HasTenant;
    use SoftDeletes;

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
