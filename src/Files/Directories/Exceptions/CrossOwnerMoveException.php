<?php

namespace Innertia\Files\Directories\Exceptions;

class CrossOwnerMoveException extends \DomainException
{
    public static function between(string $from, string $to): self
    {
        return new self("Cannot move directory across owners (from {$from} to {$to}).");
    }
}
