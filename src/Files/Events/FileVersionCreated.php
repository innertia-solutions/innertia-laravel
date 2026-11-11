<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileVersionCreated extends DomainEvent
{
    public function __construct(
        public readonly File $file,
        public readonly FileVersion $previous,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::VersionCreated;
    }

    public function payload(): array
    {
        return [
            'file_id'          => $this->file->id,
            'version'          => $this->file->version,
            'previous_version' => $this->previous->version,
            'source'           => $this->file->version_source,
        ];
    }
}
