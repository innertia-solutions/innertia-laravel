<?php

namespace Innertia\Api\UseCases;

use Innertia\Api\Models\Client;
use Innertia\Api\Models\ClientApiKey;
use Innertia\Platform\Contracts\UseCase;

class CreateApiKey extends UseCase
{
    public function __construct(
        public readonly Client          $client,
        public readonly string          $name,
        public readonly array           $permissions = [],
        public readonly ?\Carbon\Carbon $expiresAt   = null,
    ) {}

    public function execute(): array
    {
        ['raw' => $raw, 'attributes' => $attributes] = ClientApiKey::generate(
            clientId:    $this->client->id,
            name:        $this->name,
            permissions: $this->permissions,
            expiresAt:   $this->expiresAt,
        );

        return ['raw_key' => $raw, 'api_key' => ClientApiKey::create($attributes)];
    }
}
