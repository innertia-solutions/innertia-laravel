<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileRenamed extends DomainEvent
{
    public function __construct(
        public readonly File $file,
        public readonly string $oldName,
        public readonly string $newName,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::Renamed;
    }

    public function payload(): array
    {
        return [
            'file_id'  => $this->file->id,
            'old_name' => $this->oldName,
            'new_name' => $this->newName,
        ];
    }
}
