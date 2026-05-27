<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileRestored extends DomainEvent
{
    public function __construct(
        public readonly File $file,
        public readonly ?string $relocatedToDirectoryId,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::Restored;
    }

    public function payload(): array
    {
        return [
            'file_id'                   => $this->file->id,
            'relocated_to_directory_id' => $this->relocatedToDirectoryId,
        ];
    }
}
