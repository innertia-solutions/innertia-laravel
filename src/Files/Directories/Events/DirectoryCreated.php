<?php

namespace Innertia\Files\Directories\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class DirectoryCreated extends DomainEvent
{
    public function __construct(
        public readonly Directory $directory,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return DirectoryEvent::Created;
    }

    public function payload(): array
    {
        return [
            'directory_id' => $this->directory->id,
            'name'         => $this->directory->name,
            'parent_id'    => $this->directory->parent_id,
            'owner_type'   => $this->directory->owner_type,
            'owner_id'     => $this->directory->owner_id,
        ];
    }
}
