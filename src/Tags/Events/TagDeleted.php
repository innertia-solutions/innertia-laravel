<?php

namespace Innertia\Tags\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class TagDeleted extends DomainEvent
{
    public function __construct(
        public readonly string $tagId,
        public readonly string $slug,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return TagEvent::Deleted;
    }

    public function payload(): array
    {
        return [
            'tag_id' => $this->tagId,
            'slug'   => $this->slug,
        ];
    }
}
