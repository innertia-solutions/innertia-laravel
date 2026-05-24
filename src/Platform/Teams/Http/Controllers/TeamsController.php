<?php

namespace Innertia\Platform\Teams\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Innertia\Facades\Innertia;
use Innertia\Platform\Teams\Models\Team;
use Innertia\Platform\Teams\UseCases\CreateTeam;
use Innertia\Platform\Teams\UseCases\DeleteTeam;
use Innertia\Platform\Teams\UseCases\SyncTeamMembers;
use Innertia\Platform\Teams\UseCases\UpdateTeam;

/**
 * Default CRUD controller para Teams.
 *
 * Mountable via Routes::register(). Las apps pueden extender esta clase o
 * forkear el controller completo y montar su propia versión.
 *
 * Override de UseCases: el patrón recomendado es extender este controller y
 * override los métodos puntuales. Para customización ligera, el model usado
 * sale de config('innertia.teams.model') — apunta a tu subclase.
 */
class TeamsController
{
    protected function model(): string
    {
        return config('innertia.teams.model', Team::class);
    }

    /* ── Hooks de extensión (ver OrganizationsController para el patrón completo) ── */
    protected function extraStoreRules(): array { return []; }
    protected function extraUpdateRules(): array { return []; }
    protected function extraFields(Request $request, $team = null): array { return []; }

    /** Relaciones a incluir en show() — override para agregar relaciones app-specific. */
    protected function showRelations(): array
    {
        return [
            'members:id,name,email',
            'parent:id,name',
            'children:id,name,parent_team_id',
        ];
    }

    /**
     * Lista plana de teams del tenant + organization activa, con members_count.
     * El cliente arma el árbol via parent_team_id.
     */
    public function index(Request $request): JsonResponse
    {
        $teams = $this->model()::query()
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return response()->json($teams);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = Innertia::tenant()?->getKey();

        $data = $request->validate(array_merge([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string|max:255',
            'parent_team_id' => [
                'nullable', 'uuid',
                Rule::exists('teams', 'id')->whereNull('deleted_at'),
            ],
        ], $this->extraStoreRules()));

        $orgId = Innertia::organization()?->current();

        $team = (new CreateTeam(
            tenantId:       $tenantId,
            name:           $data['name'],
            description:    $data['description']    ?? null,
            parentTeamId:   $data['parent_team_id'] ?? null,
            organizationId: $orgId,
            extra:          $this->extraFields($request),
        ))->execute();

        return response()->json($team, 201);
    }

    public function show(string $id): JsonResponse
    {
        $team = $this->model()::with($this->showRelations())->findOrFail($id);
        return response()->json($team);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $team = $this->model()::findOrFail($id);

        $data = $request->validate(array_merge([
            'name'           => 'sometimes|string|max:255',
            'description'    => 'nullable|string|max:255',
            'parent_team_id' => [
                'nullable', 'uuid',
                Rule::notIn([$id]),
                Rule::exists('teams', 'id')->whereNull('deleted_at'),
            ],
        ], $this->extraUpdateRules()));

        $team = (new UpdateTeam(
            teamId:       $id,
            name:         $data['name']        ?? null,
            description:  $data['description'] ?? null,
            parentTeamId: $data['parent_team_id'] ?? null,
            clearParent:  array_key_exists('parent_team_id', $data) && $data['parent_team_id'] === null,
            extra:        $this->extraFields($request, $team),
        ))->execute();

        return response()->json($team);
    }

    public function destroy(string $id): JsonResponse
    {
        (new DeleteTeam(teamId: $id))->execute();
        return response()->json(null, 204);
    }

    public function syncMembers(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'members'                => 'present|array',
            'members.*.user_id'      => [
                'required', 'uuid',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'members.*.role_in_team' => 'sometimes|in:member,lead',
        ]);

        $team = (new SyncTeamMembers(
            teamId:  $id,
            members: $data['members'],
        ))->execute();

        return response()->json($team);
    }
}
