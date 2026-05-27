<?php

namespace Innertia\Platform\Organizations\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;

class OrganizationDeleted extends DomainEvent
{
    public function __construct(
        public readonly int               $organizationId,
        public readonly string            $key,
        public readonly string            $name,
        public readonly ?Authenticatable  $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return OrganizationEvent::Deleted;
    }

    public function payload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'key'             => $this->key,
            'name'            => $this->name,
        ];
    }
}
