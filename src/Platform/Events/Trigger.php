<?php

namespace Innertia\Platform\Events;

/**
 * Contract for classes that react to DomainEvents.
 *
 * Triggers are registered explicitly on the EventBus:
 *   Innertia::events()->trigger(MyTrigger::class);
 *
 * The bus calls handle() with the dispatched DomainEvent instance.
 * Implementing ShouldQueue (Illuminate\Contracts\Queue\ShouldQueue) makes
 * the trigger run asynchronously via Laravel's queue.
 */
interface Trigger
{
    /** What event this trigger reacts to. */
    public static function on(): DomainEventKey;

    /** Side-effect logic. Receives the dispatched event. */
    public function handle(DomainEvent $event): void;
}
