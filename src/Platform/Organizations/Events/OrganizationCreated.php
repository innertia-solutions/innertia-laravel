<?php

namespace Innertia\Platform\Organizations\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use Innertia\Platform\Organizations\Models\Organization;

class OrganizationCreated extends DomainEvent
{
    public function __construct(
        public readonly Organization      $organization,
        public readonly ?Authenticatable  $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return OrganizationEvent::Created;
    }

    public function payload(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'key'             => $this->organization->key,
            'name'            => $this->organization->name,
            'active'          => $this->organization->active,
        ];
    }
}
