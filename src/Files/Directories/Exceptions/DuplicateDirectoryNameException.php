<?php

namespace Innertia\Files\Directories\Exceptions;

class DuplicateDirectoryNameException extends \DomainException
{
    public static function forSibling(string $name, ?string $parentId): self
    {
        $where = $parentId === null ? 'at root' : "under parent {$parentId}";
        return new self("A directory named \"{$name}\" already exists {$where}.");
    }
}
