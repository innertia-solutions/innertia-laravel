<?php

namespace Innertia\Platform\Teams\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Auth\RBAC\Traits\HasRoles;
use Innertia\Traits\HasUuid;

/**
 * Team — agrupación de usuarios para asignar roles y permisos colectivamente.
 *
 * Posibles scopes:
 *   - tenant-level: organization_id NULL (single-org saas o agrupación global)
 *   - org-level:    organization_id presente (multi-org saas, team pertenece a una org)
 *
 * Polimorfismo de model_roles:
 *   model_roles(model_type='Innertia\Platform\Teams\Models\Team', model_id, role_id, organization_id)
 *
 * Members heredan roles del team via la resolución de permisos consolidada en HasRoles.
 */
class Team extends Model
{
    use HasFactory, HasUuid, HasRoles;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'parent_team_id',
        'name',
        'description',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            config('auth.providers.users.model'),
            'team_members',
            'team_id',
            'user_id'
        )->withPivot('role_in_team', 'joined_at');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_team_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_team_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Sub-teams recursivos (jerarquía). Útil para resolución de permisos en cascada. */
    public function descendants(): \Illuminate\Support\Collection
    {
        return $this->children()->with('children')->get()->flatMap(function ($child) {
            return collect([$child])->merge($child->descendants());
        });
    }

    public function isLeader(string $userId): bool
    {
        return $this->members()
            ->wherePivot('user_id', $userId)
            ->wherePivot('role_in_team', 'lead')
            ->exists();
    }
}
