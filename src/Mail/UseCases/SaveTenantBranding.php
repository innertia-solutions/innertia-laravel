<?php

namespace Innertia\Mail\UseCases;

use Innertia\Mail\Branding;
use Innertia\Mail\TenantBranding;
use Innertia\Platform\Contracts\UseCase;

/** Saves the brand of a tenant (already validated) and returns it with the resolved preview. */
class SaveTenantBranding extends UseCase
{
    public function __construct(
        public readonly ?string $tenantId,
        public readonly array $data,
    ) {}

    public function execute(): array
    {
        $stored = TenantBranding::save($this->tenantId, $this->data);

        return ['branding' => $stored, 'resolved' => Branding::for($this->tenantId)->toArray()];
    }
}
