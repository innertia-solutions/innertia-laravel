<?php

namespace Innertia\Files\Directories\Exceptions;

class MaxDepthExceededException extends \DomainException
{
    public static function at(int $depth, int $max): self
    {
        return new self("Operation would result in depth {$depth}, exceeding configured max of {$max}.");
    }
}
