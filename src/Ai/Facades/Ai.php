<?php

namespace Innertia\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static string|null configureFor(string $use)
 *
 * @see \Innertia\Ai\AiManager
 */
class Ai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Innertia\Ai\AiManager::class;
    }
}
