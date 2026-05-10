<?php

namespace Innertia\Platform\Contracts;

/**
 * Base for domain gate classes. Define one public method per ability.
 * Methods are auto-registered by DomainServiceProvider::registerGate().
 *
 * Convention: class OrdersGate + method manage → ability 'orders.manage'
 *
 * Usage:
 *   class OrdersGate extends DomainGate
 *   {
 *       public function manage(User $user, Order $order): bool
 *       {
 *           return $user->hasPermission('orders.manage');
 *       }
 *
 *       public function view(User $user): bool
 *       {
 *           return true;
 *       }
 *   }
 */
abstract class DomainGate
{
}
