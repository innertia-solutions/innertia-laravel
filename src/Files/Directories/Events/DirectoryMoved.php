<?php

namespace Innertia\Files\Directories\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class DirectoryMoved extends DomainEvent
{
    public function __construct(
        public readonly Directory $directory,
        public readonly ?string $oldParentId,
        public readonly ?string $newParentId,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return DirectoryEvent::Moved;
    }

    public function payload(): array
    {
        return [
            'directory_id'  => $this->directory->id,
            'name'          => $this->directory->name,
            'old_parent_id' => $this->oldParentId,
            'new_parent_id' => $this->newParentId,
        ];
    }
}
