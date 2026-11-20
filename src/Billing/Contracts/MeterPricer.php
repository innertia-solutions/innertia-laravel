<?php

namespace Innertia\Billing\Contracts;

use Innertia\Billing\Models\PlanMeter;
use Innertia\Billing\Models\Subscription;

/**
 * Replaces the standard calculation of one meter.
 *
 * @return array{quantity: float, unit_price: int, amount: int, description?: string, meta?: array}|null null = no line
 */
interface MeterPricer
{
    public function price(Subscription $subscription, PlanMeter $planMeter, array $usage, string $periodStart, string $periodEnd): ?array;
}
