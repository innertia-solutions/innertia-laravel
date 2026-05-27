<?php

namespace Innertia\Files\Exceptions;

class DirectoriesFeatureDisabledException extends \RuntimeException
{
    public static function forFileMove(): self
    {
        return new self('Cannot move file to a directory: Directories feature is disabled.');
    }
}
