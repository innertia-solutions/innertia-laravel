<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileUploaded extends DomainEvent
{
    public function __construct(
        public readonly File $file,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::Uploaded;
    }

    public function payload(): array
    {
        return [
            'file_id'       => $this->file->id,
            'original_name' => $this->file->original_name,
            'mime_type'     => $this->file->mime_type,
            'size'          => $this->file->size,
            'directory_id'  => $this->file->directory_id ?? null,
        ];
    }
}
