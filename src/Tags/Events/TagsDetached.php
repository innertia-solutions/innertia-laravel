<?php

namespace Innertia\Tags\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class TagsDetached extends DomainEvent
{
    public function __construct(
        public readonly Model $entity,
        public readonly array $slugs,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return TagEvent::Detached;
    }

    public function payload(): array
    {
        return [
            'entity_type' => get_class($this->entity),
            'entity_id'   => $this->entity->getKey(),
            'slugs'       => $this->slugs,
        ];
    }
}
