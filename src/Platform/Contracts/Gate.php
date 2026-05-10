<?php

namespace Innertia\Platform\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Base Gate class for domain authorization.
 *
 * Usage:
 *   class CanManageOrders extends Gate
 *   {
 *       public function authorize(Authenticatable $user, mixed ...$args): bool
 *       {
 *           [$order] = $args;
 *           return $user->hasPermission('orders.manage') || $user->id === $order->user_id;
 *       }
 *   }
 *
 * Registration (AppServiceProvider or domain ServiceProvider):
 *   Gate::define('manage-orders', CanManageOrders::class);
 *
 * Or use auto-registration by calling InnertiaServiceProvider::registerGates($directory, $namespace).
 */
abstract class Gate
{
    abstract public function authorize(Authenticatable $user, mixed ...$args): bool;

    public function __invoke(Authenticatable $user, mixed ...$args): bool
    {
        return $this->authorize($user, ...$args);
    }
}
