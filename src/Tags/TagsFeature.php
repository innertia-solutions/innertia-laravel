<?php

namespace Innertia\Tags;

/**
 * Single source of truth for whether the Tags feature is active.
 *
 * Active when config('innertia.tags.enabled') === true.
 */
final class TagsFeature
{
    public static function isActive(): bool
    {
        return (bool) config('innertia.tags.enabled', false);
    }

    public static function modelClass(): string
    {
        return config('innertia.tags.model', Models\Tag::class);
    }
}
