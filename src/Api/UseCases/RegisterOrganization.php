<?php
declare(strict_types=1);
namespace Innertia\Api\UseCases;

use Innertia\Api\Events\ApiKeyCreated;
use Innertia\Api\Events\OrganizationCreated;
use Innertia\Api\Models\ApiKey;
use Innertia\Api\Models\Organization;

class RegisterOrganization
{
    public function __construct(
        private readonly string $name,
        private readonly string $key,
        private readonly string $firstKeyName = 'Default',
    ) {}

    /** @return array{organization: Organization, raw_key: string, api_key: ApiKey} */
    public function execute(): array
    {
        $org = Organization::create([
            'name' => $this->name,
            'key'  => $this->key,
        ]);

        ['raw' => $raw, 'attributes' => $attrs] = ApiKey::generate(
            organizationId: $org->id,
            name:           $this->firstKeyName,
            isDefault:      true,
        );

        $apiKey = ApiKey::create(['organization_id' => $org->id, ...$attrs]);

        OrganizationCreated::dispatch($org);
        ApiKeyCreated::dispatch($org, $apiKey);

        return ['organization' => $org, 'raw_key' => $raw, 'api_key' => $apiKey];
    }
}
