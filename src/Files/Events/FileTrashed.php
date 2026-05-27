<?php

namespace Innertia\Files\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class FileTrashed extends DomainEvent
{
    public function __construct(
        public readonly File $file,
        public readonly string $trashGroupId,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return FileEvent::Trashed;
    }

    public function payload(): array
    {
        return [
            'file_id'       => $this->file->id,
            'trash_group_id' => $this->trashGroupId,
        ];
    }
}
