<?php

namespace Innertia\Webhooks\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Models\WebhookLog;
use Innertia\Webhooks\WebhookDeliverer;

/**
 * Re-sends a logged message now (same `webhook-id` and body, so a receiver that already processed it can
 * dedupe). Single synchronous attempt.
 */
class ResendWebhookDelivery extends UseCase
{
    public function __construct(public readonly WebhookLog $log) {}

    public function execute(): WebhookLog
    {
        $webhook = $this->log->webhook;
        $messageId = $this->log->message_id ?: WebhookDeliverer::newMessageId();

        return app(WebhookDeliverer::class)->attempt(
            $webhook,
            (string) $this->log->event_key,
            (array) $this->log->payload,
            $messageId,
            attempt: ((int) $this->log->attempts) + 1,
            maxAttempts: ((int) $this->log->attempts) + 1,
            isTest: (bool) $this->log->is_test,
        )['log'];
    }
}
