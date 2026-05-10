<?php

namespace Innertia\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Innertia\Models\Subscription;

/**
 * Allow a model's users to subscribe to its domain events.
 *
 * Usage:
 *   class Project extends Model
 *   {
 *       use Subscribable;
 *   }
 *
 *   $project->subscribe($user);
 *   $project->subscribe($user, events: ['status.changed'], channels: ['mail', 'realtime']);
 *   $project->unsubscribe($user);
 *   $project->subscribers('status.changed');
 */
trait Subscribable
{
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscribable');
    }

    public function subscribe(
        Authenticatable $user,
        array $events   = ['*'],
        array $channels = ['mail'],
    ): Subscription {
        return $this->subscriptions()->updateOrCreate(
            ['subscriber_id' => $user->getAuthIdentifier()],
            ['events' => $events, 'channels' => $channels],
        );
    }

    public function unsubscribe(Authenticatable $user): void
    {
        $this->subscriptions()
            ->where('subscriber_id', $user->getAuthIdentifier())
            ->delete();
    }

    /**
     * Get all subscribers, optionally filtered by event key.
     */
    public function subscribers(?string $eventKey = null): Collection
    {
        $subscriptions = $this->subscriptions()->with('subscriber')->get();

        if ($eventKey !== null) {
            $subscriptions = $subscriptions->filter(
                fn (Subscription $s) => $s->matchesEvent($eventKey)
            );
        }

        return $subscriptions->map(fn (Subscription $s) => $s->subscriber)->filter();
    }

    /**
     * Get subscriptions matching an event key, grouped by channel.
     * Returns: ['mail' => Collection<User>, 'realtime' => Collection<User>]
     */
    public function subscribersByChannel(string $eventKey): array
    {
        $byChannel = [];

        $this->subscriptions()
            ->with('subscriber')
            ->get()
            ->filter(fn (Subscription $s) => $s->matchesEvent($eventKey))
            ->each(function (Subscription $s) use (&$byChannel) {
                foreach ($s->channels as $channel) {
                    $byChannel[$channel][] = $s->subscriber;
                }
            });

        return array_map(
            fn ($users) => collect($users)->filter(),
            $byChannel,
        );
    }
}
