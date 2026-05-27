<?php

namespace Innertia\Platform\Teams\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use Innertia\Platform\Teams\Models\Team;

class TeamCreated extends DomainEvent
{
    public function __construct(
        public readonly Team $team,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return TeamEvent::Created;
    }

    public function payload(): array
    {
        return [
            'team_id'         => $this->team->id,
            'name'            => $this->team->name,
            'organization_id' => $this->team->organization_id,
            'parent_team_id'  => $this->team->parent_team_id,
        ];
    }
}
