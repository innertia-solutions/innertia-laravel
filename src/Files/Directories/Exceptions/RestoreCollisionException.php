<?php

namespace Innertia\Files\Directories\Exceptions;

class RestoreCollisionException extends \DomainException
{
    public static function with(string $name, ?string $parentId): self
    {
        $where = $parentId === null ? 'at root' : "under parent {$parentId}";
        return new self("Cannot restore: a live directory named \"{$name}\" already exists {$where}.");
    }
}
