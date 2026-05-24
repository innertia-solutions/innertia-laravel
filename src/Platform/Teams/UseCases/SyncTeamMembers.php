<?php

namespace Innertia\Platform\Teams\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Teams\Models\Team;

class SyncTeamMembers extends UseCase
{
    /**
     * @param array<int, array{user_id: string, role_in_team?: string}> $members
     */
    public function __construct(
        public readonly string $teamId,
        public readonly array  $members,
    ) {}

    public function execute(): Team
    {
        $model = config('innertia.teams.model', Team::class);
        $team  = $model::findOrFail($this->teamId);

        // Capturar joined_at de miembros existentes para preservarlo cuando solo
        // cambia el role (sync() normal haría detach+attach, perdiendo el original).
        $existing = $team->members()
            ->pluck('team_members.joined_at', 'team_members.user_id')
            ->all();

        $payload = [];
        foreach ($this->members as $member) {
            $userId   = $member['user_id'];
            $role     = $member['role_in_team'] ?? 'member';
            $joinedAt = $existing[$userId] ?? now();

            $payload[$userId] = [
                'role_in_team' => $role,
                'joined_at'    => $joinedAt,
            ];
        }

        $team->members()->sync($payload);

        return $team->fresh(['members', 'parent', 'children']);
    }
}
