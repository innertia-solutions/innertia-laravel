<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileHardDeleted extends DomainEvent
{
    public function __construct(
        public readonly string $fileId,
        public readonly string $fileName,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::HardDeleted;
    }

    public function payload(): array
    {
        return [
            'file_id' => $this->fileId,
            'name'    => $this->fileName,
        ];
    }
}
