<?php

namespace Innertia\Files\Directories\Events;

use Innertia\Platform\Events\DomainEventKey;

enum DirectoryEvent: string implements DomainEventKey
{
    case Created     = 'directories.created';
    case Renamed     = 'directories.renamed';
    case Moved       = 'directories.moved';
    case Trashed     = 'directories.trashed';
    case Restored    = 'directories.restored';
    case HardDeleted = 'directories.hard_deleted';

    public function key(): string
    {
        return $this->value;
    }
}
