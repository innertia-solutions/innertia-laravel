<?php

namespace Innertia\Platform\Teams\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Innertia\Platform\Teams\Models\Team;
use Innertia\Platform\Teams\TeamsFeature;

/**
 * Add to your User model to expose team membership + permission inheritance.
 *
 *   $user->teams()                      // BelongsToMany
 *   $user->teamIds()                    // array<string>  cacheable
 *   $user->permissionsViaTeams()        // array<string>  permisos heredados de teams
 *   $user->rolesViaTeams()              // Collection<Role>
 *
 * Cuando TeamsFeature está inactivo, todos los métodos retornan vacío
 * (sin queries) — productos que no usan teams pagan cero overhead.
 */
trait HasTeams
{
    public function teams(): BelongsToMany
    {
        $teamModel = config('innertia.teams.model', Team::class);

        return $this->belongsToMany(
            $teamModel,
            'team_members',
            'user_id',
            'team_id'
        )->withPivot('role_in_team', 'joined_at');
    }

    /** Lista de IDs de teams del user, scopeada por tenant en saas mode. */
    public function teamIds(): array
    {
        if (! TeamsFeature::isActive()) return [];

        return $this->teams()->pluck('teams.id')->all();
    }

    /**
     * Roles que el user hereda via sus teams. Útil para resolución consolidada.
     * Scopeado por organization si OrganizationsFeature está activo.
     */
    public function rolesViaTeams(): Collection
    {
        if (! TeamsFeature::isActive()) return collect();

        $teamIds = $this->teamIds();
        if (empty($teamIds)) return collect();

        $teamModel = config('innertia.teams.model', Team::class);

        // Aprovecha el polimorfismo de model_roles: model_type='Team' apunta a un team_id.
        $query = \DB::table('model_roles')
            ->where('model_type', $teamModel)
            ->whereIn('model_id', $teamIds);

        if (\Innertia\Platform\Organizations\OrganizationsFeature::isActive()) {
            $ctx = \Innertia\Facades\Innertia::organization();
            $scope = $ctx?->scope() ?? [];
            if (! empty($scope)) {
                $query->whereIn('organization_id', $scope);
            }
        }

        $roleIds = $query->pluck('role_id');
        if ($roleIds->isEmpty()) return collect();

        $roleModel = config('innertia.rbac.role_model', \Innertia\Auth\RBAC\Models\Role::class);

        return $roleModel::whereIn('id', $roleIds)->with('permissions')->get();
    }

    /** Permisos planos heredados de teams (unique). */
    public function permissionsViaTeams(): array
    {
        return $this->rolesViaTeams()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }
}
