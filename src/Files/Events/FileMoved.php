<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileMoved extends DomainEvent
{
    public function __construct(
        public readonly File $file,
        public readonly ?string $oldDirectoryId,
        public readonly ?string $newDirectoryId,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::Moved;
    }

    public function payload(): array
    {
        return [
            'file_id'           => $this->file->id,
            'old_directory_id'  => $this->oldDirectoryId,
            'new_directory_id'  => $this->newDirectoryId,
        ];
    }
}
