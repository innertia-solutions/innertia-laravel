<?php

namespace Innertia\Tags\Events;

use Innertia\Platform\Events\DomainEventKey;

enum TagEvent: string implements DomainEventKey
{
    case Created  = 'tags.created';
    case Updated  = 'tags.updated';
    case Deleted  = 'tags.deleted';
    case Attached = 'tags.attached';
    case Detached = 'tags.detached';
    case Synced   = 'tags.synced';

    public function key(): string
    {
        return $this->value;
    }
}
