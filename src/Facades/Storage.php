<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Storage\StorageManager;

/**
 * @method static void hydrate()
 * @method static bool configured(string $disk)
 * @method static string activeDisk()
 * @method static void hydrateCloud()
 * @method static bool cloudEnabled()
 * @method static bool cloudConfigured()
 * @method static string cloudDisk()
 * @method static array cloudSettings()
 * @method static array|null buildCloudConfig(array $settings)
 * @method static array probe(?array $settings = null)
 * @method static array probeWithRetry(?array $settings = null, int $attempts = 6)
 * @method static array provision(string $provider, array $input)
 * @method static string localPath(string $disk, string $path)
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
