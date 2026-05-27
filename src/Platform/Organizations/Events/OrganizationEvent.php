<?php

namespace Innertia\Platform\Organizations\Events;

use Innertia\Platform\Events\DomainEventKey;

enum OrganizationEvent: string implements DomainEventKey
{
    case Created = 'organizations.created';
    case Updated = 'organizations.updated';
    case Deleted = 'organizations.deleted';

    public function key(): string
    {
        return $this->value;
    }
}
