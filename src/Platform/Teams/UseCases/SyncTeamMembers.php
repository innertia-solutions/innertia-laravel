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

        $beforeIds = array_map('strval', $team->members()->pluck('team_members.user_id')->all());

        // Capturar joined_at de miembros existentes para preservarlo cuando solo
        // cambia el role (sync() normal haría detach+attach, perdiendo el original).
        $existing = collect($team->members()
            ->pluck('team_members.joined_at', 'team_members.user_id')
            ->all()
        )->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();

        $payload = [];
        foreach ($this->members as $member) {
            $userId   = (string) $member['user_id'];
            $role     = $member['role_in_team'] ?? 'member';
            $joinedAt = $existing[$userId] ?? now();

            $payload[$userId] = [
                'role_in_team' => $role,
                'joined_at'    => $joinedAt,
            ];
        }

        $team->members()->sync($payload);

        $fresh     = $team->fresh(['members', 'parent', 'children']);
        $afterIds  = array_map('strval', $fresh->members()->pluck('team_members.user_id')->all());
        $added     = array_values(array_diff($afterIds, $beforeIds));
        $removed   = array_values(array_diff($beforeIds, $afterIds));

        event(new \Innertia\Platform\Teams\Events\TeamMembersSynced($fresh, $added, $removed));

        return $fresh;
    }
}
