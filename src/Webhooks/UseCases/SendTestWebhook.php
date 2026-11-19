<?php

namespace Innertia\Webhooks\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Models\WebhookLog;
use Innertia\Webhooks\WebhookDeliverer;

/**
 * Sends a `webhook.test` event right away (synchronous single attempt, no retries, does not affect health).
 */
class SendTestWebhook extends UseCase
{
    public const EVENT = 'webhook.test';

    public function __construct(public readonly Webhook $webhook) {}

    public function execute(): WebhookLog
    {
        $body = [
            'type'      => self::EVENT,
            'timestamp' => now()->toIso8601String(),
            'data'      => ['message' => 'Evento de prueba', 'webhook_id' => $this->webhook->id],
        ];

        return app(WebhookDeliverer::class)
            ->attempt($this->webhook, self::EVENT, $body, WebhookDeliverer::newMessageId(), isTest: true)['log'];
    }
}
