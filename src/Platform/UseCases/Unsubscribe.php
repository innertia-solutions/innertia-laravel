<?php

namespace Innertia\Platform\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Models\Subscription;
use Innertia\Platform\Contracts\UseCase;

class Unsubscribe extends UseCase
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string          $subscriptionId,
    ) {}

    public function execute(): void
    {
        $subscription = Subscription::where('id', $this->subscriptionId)
            ->where('subscriber_id', $this->user->getAuthIdentifier())
            ->first();

        if (! $subscription) {
            throw new NotFoundException('Subscription not found.');
        }

        $subscription->delete();
    }
}
