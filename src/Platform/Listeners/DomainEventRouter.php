<?php

namespace Innertia\Platform\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Innertia\Mail\InnertiaMailable;
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

        // Colecciona subscribable + ancestors para fan-out.
        // Solo modelos con el trait Subscribable (tienen subscribersByChannel).
        $targets = collect([$event->subscribable()])
            ->merge($event->ancestors())
            ->filter()
            ->filter(fn ($m) => method_exists($m, 'subscribersByChannel'));

        if (in_array('mail', $channels, true)) {
            $mailable = $event->toMail();
            if ($mailable !== null) {
                foreach ($targets as $target) {
                    $this->routeMail($event, $target, $mailable);
                }
            }
        }

        if (in_array('web', $channels, true)) {
            $webData = $event->toWeb();
            if ($webData !== null) {
                foreach ($targets as $target) {
                    $this->routeWeb($event, $target, $webData);
                }
            }
        }
    }

    private function routeMail(DomainEvent $event, Model $subscribable, InnertiaMailable $mailable): void
    {
        $subscribers = $subscribable->subscribersByChannel($event->resolvedKey())['mail'] ?? collect();
        foreach ($subscribers as $user) {
            if (! empty($user->email)) {
                Mail::to($user->email)->queue(clone $mailable);
            }
        }
    }

    private function routeWeb(DomainEvent $event, Model $subscribable, array $webData): void
    {
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
