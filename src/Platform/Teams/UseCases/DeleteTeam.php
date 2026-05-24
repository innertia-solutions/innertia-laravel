<?php

namespace Innertia\Platform\Teams\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Teams\Models\Team;

class DeleteTeam extends UseCase
{
    public function __construct(public readonly string $teamId) {}

    public function execute(): void
    {
        $model = config('innertia.teams.model', Team::class);
        $model::findOrFail($this->teamId)->delete();
    }
}
