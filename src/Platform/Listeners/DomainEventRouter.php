<?php

namespace Innertia\Platform\Listeners;

use Illuminate\Support\Facades\Mail;
use Innertia\Notifications\Models\Notification;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Webhooks\WebhookService;

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

        // Colecciona subscribable + ancestors para fan-out
        $targets = collect([$event->subscribable()])
            ->merge($event->ancestors())
            ->filter();

        if (in_array('mail', $channels, true)) {
            foreach ($targets as $target) {
                $this->routeMail($event, $target);
            }
        }

        if (in_array('web', $channels, true)) {
            foreach ($targets as $target) {
                $this->routeWeb($event, $target);
            }
        }
    }

    private function routeMail(DomainEvent $event, \Illuminate\Database\Eloquent\Model $subscribable): void
    {
        $mailable = $event->toMail();
        if ($mailable === null) {
            return;
        }

        $subscribers = $subscribable->subscribersByChannel($event->resolvedKey())['mail'] ?? collect();
        foreach ($subscribers as $user) {
            if (! empty($user->email)) {
                Mail::to($user->email)->queue(clone $mailable);
            }
        }
    }

    private function routeWeb(DomainEvent $event, \Illuminate\Database\Eloquent\Model $subscribable): void
    {
        $webData = $event->toWeb();
        if ($webData === null) {
            return;
        }

        $subscribers = $subscribable->subscribersByChannel($event->resolvedKey())['web'] ?? collect();
        foreach ($subscribers as $user) {
            Notification::create([
                'user_id'    => (string) $user->getAuthIdentifier(),
                'type'       => get_class($event),
                'key'        => $event->resolvedKey(),
                'title'      => $webData['title'] ?? null,
                'body'       => $webData['body'] ?? null,
                'data'       => $event->payload(),
                'created_at' => now(),
            ]);
        }
    }
}
