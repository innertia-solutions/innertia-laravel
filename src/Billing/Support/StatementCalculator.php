<?php

namespace Innertia\Billing\Support;

use Illuminate\Support\Collection;
use Innertia\Billing\Contracts\SeatCounter;
use Innertia\Billing\Enums\AdjustmentType;
use Innertia\Billing\Enums\LineKind;
use Innertia\Billing\MeterDefinition;
use Innertia\Billing\Meters;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Models\PlanMeter;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Models\UsageRecord;

/**
 * Computes the charge of a subscription for one period (pure: writes nothing).
 *
 *   base + extra seats (SeatCounter) + metered consumption over the included quantity
 *   (+ cost-valued meters: cost × (1 + markup%) − included amount) + late usage of previous
 *   periods + current adjustments; VAT by the plan rate. Every line is rounded to the minor unit
 *   (half up); VAT is rounded once over the subtotal. Subtotal never goes below 0.
 *
 * @phpstan-type Line array{kind: string, description: string, meter: ?string, quantity: float, unit_price: int, amount: int, meta: array}
 */
class StatementCalculator
{
    public function __construct(private readonly SeatCounter $seats) {}

    /**
     * @return array{lines: array<int, array>, subtotal: int, tax: int, total: int, tax_rate: float, usage_ids: string[], adjustment_ids: string[]}
     */
    public function calculate(Subscription $subscription, Plan $plan, string $periodStart, string $periodEnd): array
    {
        $tenantId = (string) $subscription->tenant_id;
        $lines = [];
        $usageIds = [];

        // ── Base ───────────────────────────────────────────────────────────
        $base = (int) $subscription->override('base_price', $plan->base_price);
        if ($base > 0) {
            $lines[] = $this->line(LineKind::Base, "Plan {$plan->name}", null, 1, $base, $base);
        }

        // ── Seats ──────────────────────────────────────────────────────────
        $seatPrice = (int) $subscription->override('seat_price', $plan->seat_price);
        $included = (int) $subscription->override('included_seats', $plan->included_seats);
        if ($seatPrice > 0) {
            $counted = $this->seats->count($tenantId);
            $extra = max(0, $counted - $included);
            if ($extra > 0) {
                $lines[] = $this->line(LineKind::Seats, 'Usuarios adicionales', null, $extra, $seatPrice, $extra * $seatPrice,
                    ['counted' => $counted, 'included' => $included]);
            }
        }

        // ── Meters (current period + late usage) ─────────────────────────────
        [$from, $to] = BillingClock::bounds($periodStart, $periodEnd);
        $paidSince = max($subscription->paidSince(), '0000-01-01');
        [$lateFrom] = BillingClock::bounds($paidSince, $paidSince);
        $active = Meters::active();

        foreach ($plan->meters as $planMeter) {
            $definition = $active[$planMeter->meter] ?? null;
            if ($definition === null) {
                continue;
            }
            $planMeter = $this->withOverrides($subscription, $planMeter);

            $current = $this->unbilled($tenantId, $planMeter->meter, $from, $to);
            $late = $periodStart > $paidSince ? $this->unbilled($tenantId, $planMeter->meter, $lateFrom, $from) : collect();
            $usageIds = array_merge($usageIds, $current->pluck('id')->all(), $late->pluck('id')->all());

            $usage = $this->aggregate($definition, $current);
            if ($usage['records'] > 0) {
                $line = $this->meterLine($subscription, $definition, $planMeter, $usage, $periodStart, $periodEnd);
                if ($line !== null) {
                    $lines[] = $line;
                }
            }

            if ($late->isNotEmpty() && $definition->aggregation === 'sum') {
                $lateUsage = $this->aggregate($definition, $late);
                $line = $this->priced($definition, $planMeter, $lateUsage, includeAllowance: false);
                $line['kind'] = LineKind::LateUsage->value;
                $line['description'] = "{$definition->label} (consumo de períodos anteriores)";
                $lines[] = $line;
            }
        }

        // ── Adjustments ─────────────────────────────────────────────────────
        $beforeAdjustments = array_sum(array_column($lines, 'amount'));
        $adjustments = Adjustment::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('consumed_at')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $periodEnd))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $periodStart))
            ->orderBy('created_at')
            ->get();

        foreach ($adjustments as $adjustment) {
            $amount = $adjustment->percent !== null
                ? Money::round($beforeAdjustments * $adjustment->percent / 100)
                : (int) $adjustment->amount;
            $lines[] = $this->line(LineKind::Adjustment, $adjustment->reason, null, 1, $adjustment->type->sign() * $amount,
                $adjustment->type->sign() * $amount, ['adjustment_id' => $adjustment->id, 'type' => $adjustment->type->value]);
        }

        $subtotal = max(0, array_sum(array_column($lines, 'amount')));
        $taxRate = (float) $plan->tax_rate;
        $tax = Money::round($subtotal * $taxRate / 100);

        return [
            'lines'          => $lines,
            'subtotal'       => $subtotal,
            'tax'            => $tax,
            'total'          => $subtotal + $tax,
            'tax_rate'       => $taxRate,
            'usage_ids'      => array_values(array_unique($usageIds)),
            'adjustment_ids' => $adjustments->filter(fn (Adjustment $a) => ! $a->recurring || in_array($a->type, [AdjustmentType::OneOff, AdjustmentType::Credit], true))
                ->pluck('id')->all(),
        ];
    }

    /** Current consumption of a meter for the backoffice estimate (quantity, cost, estimated amount). */
    public function estimate(Subscription $subscription, PlanMeter $planMeter, string $periodStart, string $periodEnd): array
    {
        $definition = Meters::get($planMeter->meter) ?? new MeterDefinition($planMeter->meter, $planMeter->meter);
        $planMeter = $this->withOverrides($subscription, $planMeter);
        [$from, $to] = BillingClock::bounds($periodStart, $periodEnd);
        $usage = $this->aggregate($definition, $this->unbilled((string) $subscription->tenant_id, $planMeter->meter, $from, $to));
        $line = $usage['records'] > 0 ? $this->meterLine($subscription, $definition, $planMeter, $usage, $periodStart, $periodEnd) : null;

        return [
            'meter'             => $definition->toArray(),
            'quantity'          => $usage['quantity'],
            'cost'              => $usage['cost'],
            'included_quantity' => (float) $planMeter->included_quantity,
            'unit_price'        => (int) $planMeter->unit_price,
            'markup_percent'    => $planMeter->markup_percent,
            'estimated_amount'  => $line['amount'] ?? 0,
        ];
    }

    private function meterLine(Subscription $subscription, MeterDefinition $definition, PlanMeter $planMeter, array $usage, string $periodStart, string $periodEnd): ?array
    {
        $pricer = Meters::pricerFor($definition->key);
        if ($pricer !== null) {
            $custom = $pricer->price($subscription, $planMeter, $usage, $periodStart, $periodEnd);

            return $custom === null ? null : $this->line(LineKind::Meter, $custom['description'] ?? $definition->label, $definition->key,
                (float) $custom['quantity'], (int) $custom['unit_price'], (int) $custom['amount'], $custom['meta'] ?? []);
        }

        return $this->priced($definition, $planMeter, $usage, includeAllowance: true);
    }

    private function priced(MeterDefinition $definition, PlanMeter $planMeter, array $usage, bool $includeAllowance): array
    {
        $included = $includeAllowance ? (float) $planMeter->included_quantity : 0.0;

        if ($definition->valuedByCost) {
            $markup = (float) ($planMeter->markup_percent ?? 0);
            $charged = $usage['cost'] * (1 + $markup / 100);
            $amount = max(0, Money::round($charged - $included));

            return $this->line(LineKind::Meter, $markup > 0 ? "{$definition->label} (costo + {$markup}%)" : $definition->label,
                $definition->key, $usage['quantity'], 0, $amount,
                ['cost' => $usage['cost'], 'markup_percent' => $markup, 'included_amount' => $included, 'unit' => $definition->unit]);
        }

        $billable = max(0, $usage['quantity'] - $included);
        $amount = Money::round($billable * (int) $planMeter->unit_price);

        return $this->line(LineKind::Meter, $definition->label, $definition->key, $usage['quantity'], (int) $planMeter->unit_price, $amount,
            ['included' => $included, 'billable' => round($billable, 6), 'unit' => $definition->unit]);
    }

    /** @return array{quantity: float, cost: int, records: int} */
    private function aggregate(MeterDefinition $definition, Collection $records): array
    {
        return [
            'quantity' => round($definition->aggregation === 'max' ? (float) $records->max('quantity') : (float) $records->sum('quantity'), 6),
            'cost'     => (int) $records->sum('cost'),
            'records'  => $records->count(),
        ];
    }

    private function unbilled(string $tenantId, string $meter, string $from, string $to): Collection
    {
        return UsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('meter', $meter)
            ->whereNull('statement_id')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->get(['id', 'quantity', 'cost']);
    }

    /** Negotiated prices: overrides.meters.{key}.{unit_price|included_quantity|markup_percent}. */
    private function withOverrides(Subscription $subscription, PlanMeter $planMeter): PlanMeter
    {
        $overrides = (array) (($subscription->overrides ?? [])['meters'][$planMeter->meter] ?? []);  // meter keys contain dots
        if ($overrides === []) {
            return $planMeter;
        }

        $copy = clone $planMeter;
        foreach (['unit_price', 'included_quantity', 'markup_percent'] as $field) {
            if (array_key_exists($field, $overrides)) {
                $copy->setAttribute($field, $overrides[$field]);
            }
        }

        return $copy;
    }

    private function line(LineKind $kind, string $description, ?string $meter, float $quantity, int $unitPrice, int $amount, array $meta = []): array
    {
        return [
            'kind'        => $kind->value,
            'description' => $description,
            'meter'       => $meter,
            'quantity'    => $quantity,
            'unit_price'  => $unitPrice,
            'amount'      => $amount,
            'meta'        => $meta,
        ];
    }
}
