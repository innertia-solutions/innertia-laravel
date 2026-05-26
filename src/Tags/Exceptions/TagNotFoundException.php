<?php

namespace Innertia\Tags\Exceptions;

class TagNotFoundException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self("Tag with slug \"{$slug}\" not found.");
    }

    public static function forId(string $id): self
    {
        return new self("Tag with id \"{$id}\" not found.");
    }
}
