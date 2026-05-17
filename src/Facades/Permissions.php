<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Auth\RBAC\Services\PermissionsService;

/**
 * @method static array  getUserPermissions(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static void   flushUser(\Illuminate\Contracts\Auth\Authenticatable|string $user)
 * @method static void   flushRole(\Innertia\Auth\RBAC\Models\Role|string $role)
 * @method static bool   check(\Illuminate\Contracts\Auth\Authenticatable $user, string|\BackedEnum $permission)
 * @method static bool   isSuperAdmin(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static array  getHierarchy()
 * @method static array  sync(bool $prune = false)
 * @method static array  all()
 * @method static array  keys()
 * @method static array  categories()
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
