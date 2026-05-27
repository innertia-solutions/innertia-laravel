<?php

namespace Innertia\Platform\Teams\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class TeamDeleted extends DomainEvent
{
    public function __construct(
        public readonly string $teamId,
        public readonly string $name,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return TeamEvent::Deleted;
    }

    public function payload(): array
    {
        return [
            'team_id' => $this->teamId,
            'name'    => $this->name,
        ];
    }
}
