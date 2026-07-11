<?php

namespace Innertia\Saas\Events;

class TenantCreated
{
    public function __construct(
        public readonly mixed $tenant,
        public readonly ?string $ownerUserId = null,
    ) {}
}
