<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Settings\AppSettingsService;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static array getGroup(string $group)
 * @method static \Innertia\Settings\Models\Setting set(string $key, mixed $value, string $type = 'string', bool $encrypted = false)
 * @method static bool forget(string $key)
 * @method static \Innertia\Settings\AppSettingsService platform()
 * @method static \Innertia\Settings\AppSettingsService tenant(?string $tenantId = null)
 *
 * @see AppSettingsService
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AppSettingsService::class;
    }
}
