<?php

namespace Innertia\Tags\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use Innertia\Tags\Models\Tag;

class TagUpdated extends DomainEvent
{
    public function __construct(
        public readonly Tag $tag,
        public readonly array $changes,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return TagEvent::Updated;
    }

    public function payload(): array
    {
        return [
            'tag_id'  => $this->tag->id,
            'changes' => $this->changes,
        ];
    }
}
