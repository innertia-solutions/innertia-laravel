<?php
declare(strict_types=1);
namespace Innertia\Api\UseCases;

use Innertia\Api\Events\ApiKeyRevoked;
use Innertia\Api\Models\ApiKey;

class RevokeApiKey
{
    public function __construct(private readonly ApiKey $apiKey) {}

    public function execute(): void
    {
        $organization = $this->apiKey->organization;
        $this->apiKey->revoke();
        ApiKeyRevoked::dispatch($organization, $this->apiKey);
    }
}
