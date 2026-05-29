<?php

declare(strict_types=1);

namespace Innertia\Api\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Innertia\Platform\Traits\HasConfigs;
use Innertia\Platform\Traits\HasUuid;

class Organization extends Model
{
    use HasUuid, HasConfigs, SoftDeletes;

    protected $table = 'organizations';

    protected $fillable = ['parent_id', 'name', 'key', 'status'];

    protected $attributes = ['status' => 'active'];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'organization_id');
    }

    public function defaultApiKey(): ?ApiKey
    {
        return $this->apiKeys()->where('is_default', true)->whereNull('revoked_at')->first();
    }

    // ── Hierarchy helpers ─────────────────────────────────────────────────────

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Returns ancestors from immediate parent up to root.
     * E.g. for leaf: [parent, grandparent, root]
     */
    public function ancestors(): Collection
    {
        $ancestors = new Collection();
        $current = $this;

        while ($current->parent_id !== null) {
            $current = $current->parent;
            if (!$current) {
                break;
            }
            $ancestors->push($current);
        }

        return $ancestors;
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function suspend(): void
    {
        $this->update(['status' => 'suspended']);
    }

    public function reactivate(): void
    {
        $this->update(['status' => 'active']);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    // ── Route model binding ───────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    public static function findByKey(string $key): ?static
    {
        return static::where('key', $key)->first();
    }
}
