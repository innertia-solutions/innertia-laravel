<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Platform\Events\EventBus;

/**
 * @method static EventBus listen(\Innertia\Platform\Events\DomainEventKey $key, callable|string $handler)
 * @method static EventBus when(\Innertia\Platform\Events\DomainEventKey $key, callable $predicate, callable|string $handler)
 * @method static EventBus trigger(string $triggerClass)
 * @method static EventBus listenMany(array $map)
 * @method static EventBus forget(\Innertia\Platform\Events\DomainEventKey $key, callable|string|null $handler = null)
 * @method static EventBus registerCatalog(string $enumClass)
 * @method static array catalog()
 * @method static void dispatch(\Innertia\Platform\Events\DomainEvent $event)
 *
 * @see \Innertia\Platform\Events\EventBus
 */
class Events extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EventBus::class;
    }
}
