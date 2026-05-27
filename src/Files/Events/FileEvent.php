<?php

namespace Innertia\Files\Events;

use Innertia\Platform\Events\DomainEventKey;

enum FileEvent: string implements DomainEventKey
{
    case Uploaded    = 'files.uploaded';
    case Renamed     = 'files.renamed';
    case Moved       = 'files.moved';
    case Trashed     = 'files.trashed';
    case Restored    = 'files.restored';
    case HardDeleted = 'files.hard_deleted';

    public function key(): string
    {
        return $this->value;
    }
}
