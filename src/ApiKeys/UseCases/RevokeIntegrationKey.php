<?php

namespace Innertia\ApiKeys\UseCases;

use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Support\WebhookTelemetry;

/** Revokes the key immediately (401 invalid_api_key from then on). */
class RevokeIntegrationKey extends UseCase
{
    public function __construct(public readonly ApiKey $key) {}

    public function execute(): ApiKey
    {
        if ($this->key->revoked_at === null) {
            $this->key->revoke();
        }

        WebhookTelemetry::info('api_keys.revoked', ['module' => 'public_api', 'api_key_id' => $this->key->id]);

        return $this->key->refresh();
    }
}
