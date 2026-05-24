<?php

namespace Innertia\Platform\Organizations\UseCases;

use Innertia\Platform\Contracts\OrganizationContract;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Organizations\Models\Organization;

class CreateOrganization extends UseCase
{
    public function __construct(
        public readonly int|string $tenantId,
        public readonly string     $name,
        public readonly string     $key,
        public readonly bool       $active = true,
    ) {}

    public function execute(): OrganizationContract
    {
        $model = config('innertia.organizations.model', Organization::class);

        return $model::create([
            'tenant_id' => $this->tenantId,
            'name'      => $this->name,
            'key'       => $this->key,
            'active'    => $this->active,
        ]);
    }
}
