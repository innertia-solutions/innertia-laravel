<?php

namespace Innertia\Platform\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Innertia\Platform\Contracts\DomainGate;

/**
 * Base ServiceProvider for domain modules. Provides registerGate() to wire
 * a DomainGate class without filesystem discovery.
 *
 * Usage in app/Domains/Orders/OrdersServiceProvider.php:
 *
 *   class OrdersServiceProvider extends DomainServiceProvider
 *   {
 *       public function boot(): void
 *       {
 *           $this->registerGate(OrdersGate::class);
 *       }
 *   }
 *
 * OrdersGate::manage → 'orders.manage'
 * OrdersGate::view   → 'orders.view'
 */
abstract class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register all public methods of a DomainGate class as Gate abilities.
     * Ability name: {domain-prefix}.{method-kebab}
     * Prefix: class basename with 'Gate' stripped, converted to kebab-case.
     */
    protected function registerGate(string $gateClass): void
    {
        if (! is_subclass_of($gateClass, DomainGate::class)) {
            return;
        }

        $prefix  = Str::kebab(Str::beforeLast(class_basename($gateClass), 'Gate'));
        $methods = (new \ReflectionClass($gateClass))->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if (
                $method->getDeclaringClass()->getName() !== $gateClass
                || str_starts_with($method->getName(), '__')
            ) {
                continue;
            }

            $ability = $prefix . '.' . Str::kebab($method->getName());

            Gate::define($ability, [$gateClass, $method->getName()]);
        }
    }
}
