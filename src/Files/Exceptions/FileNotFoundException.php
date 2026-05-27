<?php

namespace Innertia\Files\Exceptions;

class FileNotFoundException extends \DomainException
{
    public static function forId(string $id): self
    {
        return new self("File with id \"{$id}\" not found.");
    }
}
