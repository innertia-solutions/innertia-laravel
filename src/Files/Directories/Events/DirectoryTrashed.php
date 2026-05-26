<?php

namespace Innertia\Files\Directories\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class DirectoryTrashed extends DomainEvent
{
    public function __construct(
        public readonly Directory $directory,
        public readonly string $trashGroupId,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return DirectoryEvent::Trashed;
    }

    public function payload(): array
    {
        return [
            'directory_id'   => $this->directory->id,
            'name'           => $this->directory->name,
            'trash_group_id' => $this->trashGroupId,
        ];
    }
}
