<?php
declare(strict_types=1);
namespace Innertia\Api\UseCases;

use Innertia\Api\Events\ApiKeyCreated;
use Innertia\Api\Models\ApiKey;
use Innertia\Api\Models\Organization;

class CreateApiKey
{
    public function __construct(
        private readonly Organization $organization,
        private readonly string       $name,
    ) {}

    /** @return array{raw_key: string, api_key: ApiKey} */
    public function execute(): array
    {
        ['raw' => $raw, 'attributes' => $attrs] = ApiKey::generate(
            organizationId: $this->organization->id,
            name:           $this->name,
            isDefault:      false,
        );

        $apiKey = ApiKey::create(['organization_id' => $this->organization->id, ...$attrs]);

        ApiKeyCreated::dispatch($this->organization, $apiKey);

        return ['raw_key' => $raw, 'api_key' => $apiKey];
    }
}
