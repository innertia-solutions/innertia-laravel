<?php

namespace Innertia\Files\Directories\Exceptions;

class ParentTrashedException extends \DomainException
{
    public static function for(string $parentId): self
    {
        return new self("Cannot create or modify under trashed parent {$parentId}.");
    }
}
