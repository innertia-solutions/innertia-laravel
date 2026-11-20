<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\BillingClock;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Saas\Models\Tenant;

/**
 * Opens the subscription of a production space. Testing spaces are never billed.
 * Periods are anniversaries of the start date (local dates, inclusive); with trial days the
 * first period is the trial (not billed) and the paid periods start the day after.
 * A canceled subscription is reopened in place (one row per space).
 */
class StartSubscription extends UseCase
{
    public function __construct(
        public readonly string $tenantKey,
        public readonly string $planId,
        public readonly PaymentMethod $paymentMethod = PaymentMethod::Invoice,
        public readonly array $overrides = [],
        public readonly ?string $startsAt = null,
        public readonly ?int $trialDays = null,
    ) {}

    public function execute(): Subscription
    {
        $model = config('innertia.saas.tenant_model') ?? Tenant::class;
        $tenant = $model::query()->where('key', $this->tenantKey)->first();
        if (! $tenant) {
            throw new NotFoundException("Espacio \"{$this->tenantKey}\" no encontrado.");
        }
        if ($tenant->isTesting()) {
            throw new ConflictException('Los espacios de pruebas no tienen suscripción.');
        }

        $plan = Plan::query()->where('active', true)->find($this->planId);
        if (! $plan) {
            throw new NotFoundException('Plan no encontrado o inactivo.');
        }

        $existing = Subscription::query()->where('tenant_id', (string) $tenant->getKey())->first();
        if ($existing && $existing->status !== SubscriptionStatus::Canceled) {
            throw new ConflictException('El espacio ya tiene una suscripción.');
        }

        $start = $this->startsAt ? BillingClock::date($this->startsAt) : BillingClock::today();
        $trialDays = $this->trialDays ?? $plan->trial_days;
        $trialEnd = $trialDays > 0 ? $start->addDays($trialDays - 1) : null;
        $periodEnd = $trialEnd ?? BillingClock::periodEnd($start, $plan->interval->months());

        $attributes = [
            'tenant_id'            => (string) $tenant->getKey(),
            'plan_id'              => $plan->id,
            'status'               => $trialEnd ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'started_at'           => $start->format('Y-m-d'),
            'trial_ends_at'        => $trialEnd?->format('Y-m-d'),
            'current_period_start' => $start->format('Y-m-d'),
            'current_period_end'   => $periodEnd->format('Y-m-d'),
            'payment_method'       => $this->paymentMethod,
            'overrides'            => $this->overrides ?: null,
            'pending_plan_id'      => null,
            'suspended_manually'   => false,
            'suspended_at'         => null,
            'canceled_at'          => null,
        ];

        $subscription = $existing ? tap($existing)->update($attributes) : Subscription::create($attributes);

        BillingLog::info('subscription started', [
            'subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id, 'plan' => $plan->key,
            'trial_ends_at' => $attributes['trial_ends_at'],
        ]);

        return $subscription->fresh(['plan']);
    }
}
