<?php

namespace Innertia\Files\Exceptions;

class InvalidFileNameException extends \DomainException
{
    public static function empty(): self
    {
        return new self('File name cannot be empty or whitespace.');
    }

    public static function tooLong(): self
    {
        return new self('File name exceeds 255 characters.');
    }

    public static function containsSeparator(string $name): self
    {
        return new self("File name \"{$name}\" contains a path separator (/ or \\).");
    }
}
