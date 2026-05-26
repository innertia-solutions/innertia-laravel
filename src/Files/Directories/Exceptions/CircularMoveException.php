<?php

namespace Innertia\Files\Directories\Exceptions;

class CircularMoveException extends \DomainException
{
    public static function selfMove(): self
    {
        return new self('Cannot move a directory to itself.');
    }

    public static function intoDescendant(string $dirId, string $targetId): self
    {
        return new self("Cannot move directory {$dirId} into its descendant {$targetId}.");
    }
}
