<?php

namespace Innertia\Platform\Teams\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Teams\Models\Team;

class UpdateTeam extends UseCase
{
    public function __construct(
        public readonly string  $teamId,
        public readonly ?string $name         = null,
        public readonly ?string $description  = null,
        public readonly ?string $parentTeamId = null,
        public readonly bool    $clearParent  = false,
    ) {}

    public function execute(): Team
    {
        $model = config('innertia.teams.model', Team::class);
        $team  = $model::findOrFail($this->teamId);

        $team->fill(array_filter([
            'name'        => $this->name,
            'description' => $this->description,
        ], fn ($v) => $v !== null));

        if ($this->parentTeamId !== null) {
            $team->parent_team_id = $this->parentTeamId;
        } elseif ($this->clearParent) {
            $team->parent_team_id = null;
        }

        $team->save();

        return $team;
    }
}
