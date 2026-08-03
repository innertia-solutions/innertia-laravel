<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Storage\StorageManager;

/**
 * @method static void hydrate()
 * @method static bool configured(string $disk)
 * @method static string activeDisk()
 *
 * @see StorageManager
 */
class Storage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StorageManager::class;
    }
}
