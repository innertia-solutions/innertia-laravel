<?php

namespace Innertia\Webhooks\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Support\WebhookTelemetry;

/** Deletes the subscription and its delivery log (cascade). Pending retries are dropped (missing model). */
class DeleteWebhook extends UseCase
{
    public function __construct(public readonly Webhook $webhook) {}

    public function execute(): void
    {
        $id = $this->webhook->id;
        $this->webhook->logs()->delete();
        $this->webhook->delete();

        WebhookTelemetry::info('webhooks.deleted', ['webhook_id' => $id]);
    }
}
