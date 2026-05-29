<?php
declare(strict_types=1);
namespace Innertia\Api\Events;

use Innertia\Platform\Events\DomainEventKey;

enum OrganizationEvent: string implements DomainEventKey
{
    case Created     = 'organization.created';
    case Suspended   = 'organization.suspended';
    case Reactivated = 'organization.reactivated';
    case KeyCreated  = 'organization.api_key.created';
    case KeyRevoked  = 'organization.api_key.revoked';

    public function key(): string
    {
        return $this->value;
    }
}
