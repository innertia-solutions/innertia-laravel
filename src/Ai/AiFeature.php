<?php

namespace Innertia\Ai;

/**
 * Single source of truth for whether the Ai feature is active.
 *
 * Active when config('innertia.ai.enabled') === true.
 */
final class AiFeature
{
    public static function isActive(): bool
    {
        return (bool) config('innertia.ai.enabled', false);
    }
}
