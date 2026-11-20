<?php

namespace Innertia\Billing\Support;

use Innertia\Billing\Contracts\SeatCounter;
use Innertia\Billing\Http\Present;
use Innertia\Billing\Models\Subscription;

/** Plan + current period consumption (estimate) of a space, for platform and backoffice. */
final class SpaceOverview
{
    public static function build(object $tenant): array
    {
        $tenantId = (string) $tenant->getKey();
        $subscription = Subscription::query()->with('plan.meters')->where('tenant_id', $tenantId)->first();
        $testing = method_exists($tenant, 'isTesting') && $tenant->isTesting();

        $out = [
            'space'        => ['key' => $tenant->key, 'name' => $tenant->name, 'environment' => $tenant->environment?->value ?? 'production'],
            'testing'      => $testing,
            'subscription' => Present::subscription($subscription),
            'plan'         => $subscription?->plan ? Present::plan($subscription->plan) : null,
            'usage'        => [],
            'seats'        => null,
            'estimate'     => null,
        ];

        if ($subscription === null || $subscription->plan === null) {
            return $out;
        }

        $plan = $subscription->plan;
        $start = $subscription->current_period_start->format('Y-m-d');
        $end = $subscription->current_period_end->format('Y-m-d');
        $calculator = app(StatementCalculator::class);

        $out['usage'] = $plan->meters->map(fn ($pm) => $calculator->estimate($subscription, $pm, $start, $end))->values()->all();

        $counted = app(SeatCounter::class)->count($tenantId);
        $included = (int) $subscription->override('included_seats', $plan->included_seats);
        $out['seats'] = [
            'counted'    => $counted,
            'included'   => $included,
            'seat_price' => (int) $subscription->override('seat_price', $plan->seat_price),
        ];

        $base = (int) $subscription->override('base_price', $plan->base_price);
        $seatsAmount = max(0, $counted - $included) * $out['seats']['seat_price'];
        $subtotal = $base + $seatsAmount + array_sum(array_column($out['usage'], 'estimated_amount'));
        $out['estimate'] = [
            'base'     => $base,
            'seats'    => $seatsAmount,
            'usage'    => array_sum(array_column($out['usage'], 'estimated_amount')),
            'subtotal' => $subtotal,
            'tax'      => Money::round($subtotal * $plan->tax_rate / 100),
            'total'    => $subtotal + Money::round($subtotal * $plan->tax_rate / 100),
            'currency' => $plan->currency,
        ];

        return $out;
    }
}
