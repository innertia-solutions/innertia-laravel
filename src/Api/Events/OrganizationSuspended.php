<?php
declare(strict_types=1);
namespace Innertia\Api\Events;

use Innertia\Api\Models\Organization;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class OrganizationSuspended extends DomainEvent
{
    public function __construct(public readonly Organization $organization) {}

    public function key(): DomainEventKey
    {
        return OrganizationEvent::Suspended;
    }
}
