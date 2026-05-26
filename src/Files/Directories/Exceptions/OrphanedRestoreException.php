<?php

namespace Innertia\Files\Directories\Exceptions;

class OrphanedRestoreException extends \DomainException
{
    public static function for(string $dirId): self
    {
        return new self("Cannot restore directory {$dirId}: original parent no longer exists. Provide a relocate parent.");
    }
}
