<?php

namespace Innertia\Platform\Organizations\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use Innertia\Platform\Organizations\Models\Organization;

class OrganizationUpdated extends DomainEvent
{
    public function __construct(
        public readonly Organization      $organization,
        public readonly array             $changes,
        public readonly ?Authenticatable  $performedBy = null,
    ) {}

    public function key(): DomainEventKey
    {
        return OrganizationEvent::Updated;
    }

    public function payload(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'changes'         => $this->changes,
        ];
    }
}
