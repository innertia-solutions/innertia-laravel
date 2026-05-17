<?php

namespace Innertia\Api\UseCases;

use Innertia\Api\Models\Client;
use Innertia\Api\Models\ClientApiKey;
use Innertia\Platform\Contracts\UseCase;

class RegisterClient extends UseCase
{
    public function __construct(
        public readonly string $product,
        public readonly string $tenant,
        public readonly string $name,
        public readonly array  $tags           = [],
        public readonly array  $options        = [],
        public readonly string $firstKeyName   = 'Default',
        public readonly array  $firstKeyPerms  = [],
    ) {}

    public function execute(): array
    {
        $client = Client::create([
            'product' => $this->product,
            'tenant'  => $this->tenant,
            'name'    => $this->name,
            'tags'    => $this->tags,
            'options' => $this->options,
        ]);

        ['raw' => $raw, 'attributes' => $attributes] = ClientApiKey::generate(
            clientId:    $client->id,
            name:        $this->firstKeyName,
            permissions: $this->firstKeyPerms,
        );

        $apiKey = ClientApiKey::create($attributes);

        return ['client' => $client, 'raw_key' => $raw, 'api_key' => $apiKey];
    }
}
