<?php

namespace Innertia\Saas\UseCases;

use Illuminate\Support\Arr;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Saas\Models\Client;

class UpdateClient extends UseCase
{
    public function __construct(
        public readonly Client $client,
        public readonly array  $attributes,
    ) {}

    public function execute(): Client
    {
        $this->client->update(Arr::only($this->attributes, ['name', ...CreateClient::EDITABLE]));

        return $this->client;
    }
}
