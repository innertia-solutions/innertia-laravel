<?php

use Innertia\Platform\Events\DomainEventKey;

enum SampleFooEvent: string implements DomainEventKey
{
    case Created = 'foo.created';
    case Updated = 'foo.updated';

    public function key(): string
    {
        return $this->value;
    }
}
