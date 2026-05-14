<?php

namespace Innertia\Platform\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Models\Subscription;
use Innertia\Platform\Contracts\UseCase;

class UpdateSubscription extends UseCase
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string          $subscriptionId,
        public readonly ?array          $events   = null,
        public readonly ?array          $channels = null,
    ) {
       
    }

    public function execute(): Subscription
    {
        $subscription = Subscription::where('id', $this->subscriptionId)
            ->where('subscriber_id', $this->user->getAuthIdentifier())
            ->first();

        if (! $subscription) {
            throw new NotFoundException('Subscription not found.');
        }

        if ($this->events !== null) {
            $subscription->events = $this->events;
        }

        if ($this->channels !== null) {
            $subscription->channels = $this->channels;
        }

        $subscription->save();

        return $subscription;
    }
}
