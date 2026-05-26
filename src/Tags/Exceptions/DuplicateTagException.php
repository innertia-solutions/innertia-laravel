<?php

namespace Innertia\Tags\Exceptions;

class DuplicateTagException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self("A tag with slug \"{$slug}\" already exists in this scope.");
    }
}
