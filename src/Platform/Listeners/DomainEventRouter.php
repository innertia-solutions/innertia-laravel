<?php

namespace Innertia\Platform\Listeners;

use Illuminate\Support\Facades\Mail;
use Innertia\Models\Notification;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Webhook\WebhookService;

/**
 * Routes a DomainEvent to its configured delivery channels.
 * Registered automatically by InnertiaServiceProvider.
 *
 * Channels:
 *   'realtime' — Laravel broadcast (ShouldBroadcast), handled automatically
 *   'webhook'  — dispatches to registered webhook endpoints
 *   'mail'     — sends email to subscribers via toMail()
 *   'web'      — creates notification record for the frontend notification center
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

        if (in_array('web', $channels, true)) {
            $this->routeWeb($event);
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

    private function routeWeb(DomainEvent $event): void
    {
        $webData = $event->toWeb();

        if ($webData === null) {
            return;
        }

        $subscribable = $event->subscribable();

        if ($subscribable === null) {
            return;
        }

        $subscribers = $subscribable->subscribersByChannel($event->webhookKey())['web'] ?? collect();

        foreach ($subscribers as $user) {
            Notification::create([
                'user_id'    => (string) $user->getAuthIdentifier(),
                'type'       => get_class($event),
                'key'        => $event->webhookKey(),
                'title'      => $webData['title'] ?? null,
                'body'       => $webData['body'] ?? null,
                'data'       => $event->payload(),
                'created_at' => now(),
            ]);
        }
    }
}
