<?php

namespace Innertia\Files\Exceptions;

class OrphanedFileRestoreException extends \DomainException
{
    public static function for(string $fileId): self
    {
        return new self("Cannot restore file {$fileId}: original directory no longer exists. Provide a relocate directory_id.");
    }
}
