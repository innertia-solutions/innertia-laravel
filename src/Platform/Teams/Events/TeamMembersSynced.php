<?php

namespace Innertia\Platform\Teams\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use Innertia\Platform\Teams\Models\Team;

class TeamMembersSynced extends DomainEvent
{
    public function __construct(
        public readonly Team $team,
        public readonly array $added,
        public readonly array $removed,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return TeamEvent::MembersSynced;
    }

    public function payload(): array
    {
        return [
            'team_id' => $this->team->id,
            'added'   => $this->added,
            'removed' => $this->removed,
        ];
    }
}
