<?php

namespace Innertia\Tags\Exceptions;

class FeatureDisabledException extends \RuntimeException
{
    public static function tags(): self
    {
        return new self('Tags feature is disabled. Set INNERTIA_TAGS_ENABLED=true and run innertia:tags:install.');
    }
}
