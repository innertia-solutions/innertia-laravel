<?php

namespace Innertia\Webhooks\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Support\WebhookTelemetry;

/**
 * Partial update. Re-activating a subscription disabled for failures clears its health state.
 *
 *   (new UpdateWebhook($webhook, ['active' => true, 'events' => ['call.ended']]))->execute();
 */
class UpdateWebhook extends UseCase
{
    private const FIELDS = ['url', 'events', 'description', 'active', 'organization_id', 'scopes'];

    public function __construct(public readonly Webhook $webhook, public readonly array $changes) {}

    public function execute(): Webhook
    {
        $changes = array_intersect_key($this->changes, array_flip(self::FIELDS));

        if (array_key_exists('url', $changes)) {
            WebhookRules::assertUrl((string) $changes['url']);
        }
        if (array_key_exists('events', $changes)) {
            WebhookRules::assertEvents((array) $changes['events']);
            $changes['events'] = array_values(array_unique($changes['events']));
        }
        if (array_key_exists('scopes', $changes)) {
            WebhookRules::assertScopes((array) $changes['scopes']);
            $changes['scopes'] = array_values(array_unique((array) $changes['scopes']));
        }

        if (($changes['active'] ?? null) === true && ! $this->webhook->isLegacy()) {
            $changes += ['disabled_at' => null, 'disabled_reason' => null, 'failing_since' => null];
        }

        $this->webhook->update($changes);

        WebhookTelemetry::info('webhooks.updated', ['webhook_id' => $this->webhook->id, 'fields' => array_keys($changes)]);

        return $this->webhook->refresh();
    }
}
