<?php

namespace Innertia\Platform\Events;

enum EntityEventKey: string implements DomainEventKey
{
    case Changed = 'entity.changed';

    public function key(): string
    {
        return $this->value;
    }
}
