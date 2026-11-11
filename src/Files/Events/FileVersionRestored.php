<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileVersionRestored extends DomainEvent
{
    public function __construct(
        public readonly File $file,
        public readonly int $restoredFrom,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::VersionRestored;
    }

    public function payload(): array
    {
        return [
            'file_id'       => $this->file->id,
            'version'       => $this->file->version,
            'restored_from' => $this->restoredFrom,
            'performed_by'  => $this->performedBy?->getAuthIdentifier(),
        ];
    }
}
