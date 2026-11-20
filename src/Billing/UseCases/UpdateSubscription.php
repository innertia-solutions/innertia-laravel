<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Payment method, negotiated prices (overrides) and plan change. A plan change takes effect
 * from the next period (pending_plan_id, no proration); choosing the current plan clears it.
 */
class UpdateSubscription extends UseCase
{
    public function __construct(public readonly Subscription $subscription, public readonly array $data) {}

    public function execute(): Subscription
    {
        $changes = [];

        if (array_key_exists('payment_method', $this->data)) {
            $changes['payment_method'] = PaymentMethod::from($this->data['payment_method']);
        }
        if (array_key_exists('overrides', $this->data)) {
            $changes['overrides'] = $this->data['overrides'] ?: null;
        }
        if (array_key_exists('plan_id', $this->data)) {
            $planId = $this->data['plan_id'];
            if ($planId !== null && ! Plan::query()->where('active', true)->whereKey($planId)->exists()) {
                throw new NotFoundException('Plan no encontrado o inactivo.');
            }
            $changes['pending_plan_id'] = $planId === null || $planId === $this->subscription->plan_id ? null : $planId;
        }

        $this->subscription->update($changes);

        BillingLog::info('subscription updated', [
            'subscription_id' => $this->subscription->id,
            'fields'          => array_keys($changes),
            'pending_plan_id' => $this->subscription->pending_plan_id,
        ]);

        return $this->subscription->fresh(['plan', 'pendingPlan']);
    }
}
