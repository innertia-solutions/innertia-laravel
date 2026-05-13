<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\InnertiaManager;
use Innertia\Saas\Models\Tenant;

/**
 * @method static Tenant|null tenant(?string $key = null)
 * @method static Tenant|null activate(string $key)
 * @method static void        deactivate()
 *
 * @see InnertiaManager
 */
class Innertia extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InnertiaManager::class;
    }
}
