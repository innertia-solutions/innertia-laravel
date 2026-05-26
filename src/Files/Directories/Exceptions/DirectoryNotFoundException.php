<?php

namespace Innertia\Files\Directories\Exceptions;

class DirectoryNotFoundException extends \DomainException
{
    public static function forId(string $id): self
    {
        return new self("Directory with id \"{$id}\" not found.");
    }
}
