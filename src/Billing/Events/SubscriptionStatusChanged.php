<?php

namespace Innertia\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Subscription;

class SubscriptionStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly SubscriptionStatus $from,
        public readonly SubscriptionStatus $to,
        public readonly string $reason,
    ) {}
}
