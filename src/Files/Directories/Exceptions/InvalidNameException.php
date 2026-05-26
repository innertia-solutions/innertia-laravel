<?php

namespace Innertia\Files\Directories\Exceptions;

class InvalidNameException extends \DomainException
{
    public static function empty(): self
    {
        return new self('Directory name cannot be empty or whitespace.');
    }

    public static function tooLong(): self
    {
        return new self('Directory name exceeds 255 characters.');
    }

    public static function containsSeparator(string $name): self
    {
        return new self("Directory name \"{$name}\" contains a path separator (/ or \\).");
    }
}
