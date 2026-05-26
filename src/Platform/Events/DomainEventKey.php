<?php

namespace Innertia\Platform\Events;

/**
 * Marker interface for enums that catalog domain events.
 *
 * Backed string enums implement this so the EventBus can use them as
 * type-safe keys instead of stringly-typed event names.
 *
 * Convention: case values are namespaced snake.case ('feature.verb').
 *
 *   enum DirectoryEvent: string implements DomainEventKey
 *   {
 *       case Created = 'directories.created';
 *       case Moved   = 'directories.moved';
 *
 *       public function key(): string
 *       {
 *           return $this->value;
 *       }
 *   }
 */
interface DomainEventKey
{
    /** The canonical string key for this event. */
    public function key(): string;
}
