<?php

namespace Innertia\Api\UseCases;

use Innertia\Api\Models\ClientApiKey;
use Innertia\Platform\Contracts\UseCase;

class RevokeApiKey extends UseCase
{
    public function __construct(public readonly ClientApiKey $apiKey) {}

    public function execute(): void
    {
        $this->apiKey->revoke();
    }
}
