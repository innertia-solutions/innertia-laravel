<?php

namespace Innertia\Platform\Events;

/**
 * Marker interface implemented by all DomainEvent subclasses.
 *
 * Laravel's event dispatcher fires listeners registered for an interface
 * when a class implementing that interface is dispatched. This allows the
 * EventBus bridge (and DomainEventRouter) to receive ALL domain events,
 * regardless of which concrete subclass is dispatched.
 *
 * @see DomainEvent
 */
interface IsDomainEvent
{
}
