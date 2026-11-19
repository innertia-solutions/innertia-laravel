<?php

namespace Innertia\Webhooks\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Signing\StandardWebhookSigner;
use Innertia\Webhooks\Support\WebhookTelemetry;

/**
 * New secret; the previous one keeps signing (second `v1,` signature) for 24 h so the receiver can switch.
 * A legacy subscription becomes v2 (its plaintext secret is the "previous" one during the window).
 */
class RotateWebhookSecret extends UseCase
{
    public function __construct(public readonly Webhook $webhook) {}

    /** @return array{webhook: Webhook, secret: string} */
    public function execute(): array
    {
        $previous = $this->webhook->currentSecret();
        $secret = StandardWebhookSigner::generateSecret();
        $hours = (int) config('innertia.webhooks.rotation_overlap_hours', 24);

        $this->webhook->update([
            'secret'                     => '',
            'signing_secret'             => $secret,
            'secret_hint'                => StandardWebhookSigner::hint($secret),
            'previous_secret'            => $previous !== '' ? $previous : null,
            'previous_secret_expires_at' => $previous !== '' ? now()->addHours($hours) : null,
        ]);

        WebhookTelemetry::info('webhooks.secret_rotated', ['webhook_id' => $this->webhook->id]);

        return ['webhook' => $this->webhook->refresh(), 'secret' => $secret];
    }
}
