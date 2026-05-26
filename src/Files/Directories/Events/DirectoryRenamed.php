<?php

namespace Innertia\Files\Directories\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class DirectoryRenamed extends DomainEvent
{
    public function __construct(
        public readonly Directory $directory,
        public readonly string $oldName,
        public readonly string $newName,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return DirectoryEvent::Renamed;
    }

    public function payload(): array
    {
        return [
            'directory_id' => $this->directory->id,
            'old_name'     => $this->oldName,
            'new_name'     => $this->newName,
        ];
    }
}
