<?php

namespace Innertia\Files\Directories\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class DirectoryHardDeleted extends DomainEvent
{
    public function __construct(
        public readonly string $directoryId,
        public readonly string $name,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return DirectoryEvent::HardDeleted;
    }

    public function payload(): array
    {
        return [
            'directory_id' => $this->directoryId,
            'name'         => $this->name,
        ];
    }
}
