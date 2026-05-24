<?php

namespace Innertia\Platform\Teams\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Teams\Models\Team;

class CreateTeam extends UseCase
{
    public function __construct(
        public readonly int|string  $tenantId,
        public readonly string      $name,
        public readonly ?string     $description    = null,
        public readonly ?string     $parentTeamId   = null,
        public readonly int|string|null $organizationId = null,
    ) {}

    public function execute(): Team
    {
        $model = config('innertia.teams.model', Team::class);

        return $model::create([
            'tenant_id'       => $this->tenantId,
            'organization_id' => $this->organizationId,
            'name'            => $this->name,
            'description'     => $this->description,
            'parent_team_id'  => $this->parentTeamId,
        ]);
    }
}
