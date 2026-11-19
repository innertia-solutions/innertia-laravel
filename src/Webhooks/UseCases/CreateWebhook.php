<?php

namespace Innertia\Webhooks\UseCases;

use Innertia\Facades\Innertia;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Signing\StandardWebhookSigner;
use Innertia\Webhooks\Support\WebhookTelemetry;

/**
 * Creates a v2 subscription in the active tenant. Returns the model and the secret (shown once).
 *
 *   ['webhook' => Webhook, 'secret' => 'whsec_…'] = (new CreateWebhook($url, ['call.*']))->execute();
 */
class CreateWebhook extends UseCase
{
    public function __construct(
        public readonly string $url,
        public readonly array $events,
        public readonly ?string $description = null,
        public readonly ?string $organizationId = null,
        public readonly array $scopes = [],
        public readonly bool $active = true,
        public readonly ?string $createdBy = null,
    ) {}

    /** @return array{webhook: Webhook, secret: string} */
    public function execute(): array
    {
        WebhookRules::assertUrl($this->url);
        WebhookRules::assertEvents($this->events);
        WebhookRules::assertScopes($this->scopes);

        $secret = StandardWebhookSigner::generateSecret();

        $attributes = [
            'url'             => $this->url,
            'description'     => $this->description,
            'events'          => array_values(array_unique($this->events)),
            'secret'          => '', // legacy column (NOT NULL); v2 secret is encrypted in signing_secret
            'signing_secret'  => $secret,
            'secret_hint'     => StandardWebhookSigner::hint($secret),
            'active'          => $this->active,
            'organization_id' => $this->organizationId,
            'scopes'          => array_values(array_unique($this->scopes)),
            'created_by'      => $this->createdBy ?? (auth()->check() ? (string) auth()->id() : null),
        ];

        if (Innertia::tenancyEnabled()) {
            $attributes['tenant_id'] = Innertia::tenant()?->getKey();
        }

        $webhook = Webhook::create($attributes);

        WebhookTelemetry::info('webhooks.created', ['webhook_id' => $webhook->id, 'events' => $webhook->events]);

        return ['webhook' => $webhook, 'secret' => $secret];
    }
}
