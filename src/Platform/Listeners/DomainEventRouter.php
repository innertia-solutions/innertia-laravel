<?php

namespace Innertia\Platform\Listeners;

use Illuminate\Support\Facades\Mail;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Webhook\WebhookService;

/**
 * Routes a DomainEvent to its configured delivery channels.
 * Registered automatically by InnertiaServiceProvider.
 *
 * Channels handled here: 'webhook', 'mail'
 * Channel 'realtime' is handled by Laravel's ShouldBroadcast automatically.
 */
class DomainEventRouter
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function handle(DomainEvent $event): void
    {
        $channels = $event->resolveChannels();

        if (in_array('webhook', $channels, true)) {
            $this->webhooks->dispatchForEvent($event);
        }

        if (in_array('mail', $channels, true)) {
            $this->routeMail($event);
        }
    }

    private function routeMail(DomainEvent $event): void
    {
        $mailable = $event->toMail();

        if ($mailable === null) {
            return;
        }

        $subscribable = $event->subscribable();

        if ($subscribable === null) {
            return;
        }

        $subscribers = $subscribable->subscribersByChannel($event->webhookKey())['mail'] ?? collect();

        foreach ($subscribers as $user) {
            if (! empty($user->email)) {
                Mail::to($user->email)->queue(clone $mailable);
            }
        }
    }
}
