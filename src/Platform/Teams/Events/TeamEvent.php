<?php

namespace Innertia\Platform\Teams\Events;

use Innertia\Platform\Events\DomainEventKey;

enum TeamEvent: string implements DomainEventKey
{
    case Created       = 'teams.created';
    case Updated       = 'teams.updated';
    case Deleted       = 'teams.deleted';
    case MembersSynced = 'teams.members_synced';

    public function key(): string
    {
        return $this->value;
    }
}
