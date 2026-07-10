<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\InnertiaManager;
use Innertia\Saas\Models\Tenant;

/**
 * @method static bool        tenancyEnabled()
 * @method static string|null context()
 * @method static Tenant|null tenant(?string $key = null)
 * @method static Tenant|null activate(string $key)
 * @method static void        deactivate()
 * @method static \Innertia\Platform\Organizations\OrganizationContext|null organization()
 *
 * @see InnertiaManager
 */
class Innertia extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InnertiaManager::class;
    }

    public static function events(): \Innertia\Platform\Events\EventBus
    {
        return app(\Innertia\Platform\Events\EventBus::class);
    }
}
