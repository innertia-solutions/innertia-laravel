<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Services\PermissionsService;

/**
 * @method static array sync(bool $prune = false)
 * @method static array all()
 * @method static array keys()
 * @method static array categories()
 *
 * @see PermissionsService
 */
class Permissions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PermissionsService::class;
    }
}
