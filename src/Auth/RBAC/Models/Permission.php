<?php

namespace Innertia\Auth\RBAC\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Innertia\Facades\Innertia;

/**
 * Named (app-level) permission.
 *
 * Permissions define what actions exist in the system ('users.view', 'clients.manage').
 * They are defined in code (config/enums) and optionally synced to DB with descriptions.
 * The app works without running sync — permissions are created lazily via findOrCreate().
 *
 * For granting access to a specific model instance (row-level), use EntityPermission.
 *
 * @property string      $id
 * @property string|null $tenant_id
 * @property string      $name
 * @property string|null $description
 */
class Permission extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'name', 'description'];

    // ── Static factories ──────────────────────────────────────────────────────

    /**
     * Find an existing named permission or create it lazily.
     *
     * When created lazily (description = null), run `artisan innertia:permissions`
     * to backfill descriptions from the config/enum definitions.
     */
    public static function findOrCreate(string $name, ?string $description = null): static
    {
        $lookup = ['name' => $name];

        if (Innertia::tenancyEnabled()) {
            $lookup['tenant_id'] = Innertia::tenant() ? (string) Innertia::tenant()->getKey() : null;
        }

        return static::firstOrCreate($lookup, ['description' => $description]);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope to named permissions (excludes nothing — all rows are named now).
     * Kept for readability and forward-compat.
     */
    public function scopeNamed(Builder $query): Builder
    {
        return $query;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
