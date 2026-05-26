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

use Innertia\Platform\Events\DomainEvent;

class SampleFooCreated extends DomainEvent
{
    public function __construct(public readonly string $name) {}

    public function key(): DomainEventKey
    {
        return SampleFooEvent::Created;
    }
}

class SampleFooUpdatedWithVariant extends DomainEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $field,
    ) {}

    public function key(): DomainEventKey
    {
        return SampleFooEvent::Updated;
    }

    public function variant(): ?string
    {
        return $this->field;
    }
}
