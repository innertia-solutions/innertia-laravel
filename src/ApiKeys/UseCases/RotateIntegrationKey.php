<?php

namespace Innertia\ApiKeys\UseCases;

use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Support\WebhookTelemetry;

/**
 * Issues a replacement key (same name, scopes, sede and expiry) and keeps the old one valid for 24 h
 * (or until its own earlier expiry) so the integration can switch without downtime.
 */
class RotateIntegrationKey extends UseCase
{
    public function __construct(public readonly ApiKey $key) {}

    /** @return array{key: ApiKey, secret: string, previous: ApiKey} */
    public function execute(): array
    {
        $result = (new CreateIntegrationKey(
            name: (string) $this->key->name,
            scopes: $this->key->scopes(),
            organizationId: $this->key->organization_id !== null ? (string) $this->key->organization_id : null,
            expiresAt: $this->key->expires_at,
            createdBy: auth()->check() ? (string) auth()->id() : $this->key->created_by,
        ))->execute();

        $graceUntil = now()->addHours((int) config('innertia.public_api.rotation_overlap_hours', 24));
        $expiresAt = $this->key->expires_at && $this->key->expires_at->lt($graceUntil) ? $this->key->expires_at : $graceUntil;

        $this->key->update(['expires_at' => $expiresAt, 'rotated_to_id' => $result['key']->id, 'rotated_at' => now()]);

        WebhookTelemetry::info('api_keys.rotated', ['module' => 'public_api', 'api_key_id' => $this->key->id, 'new_api_key_id' => $result['key']->id]);

        return ['key' => $result['key'], 'secret' => $result['secret'], 'previous' => $this->key->refresh()];
    }
}
