<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Models\UsageRecord;
use Innertia\Billing\Support\BillingClock;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\StatementCalculator;
use Innertia\Billing\Support\SubscriptionState;
use Innertia\Platform\Contracts\UseCase;

/**
 * Closes every finished period of one subscription (catches up missed days).
 *
 * Per period: statement + lines (StatementCalculator), usage marked with the statement, one-off
 * adjustments consumed, draft invoice assembled (AssembleInvoice), period advanced and the
 * pending plan applied. A trial period produces no statement: it just turns the subscription active.
 *
 * Idempotent and safe to run twice: a cache lock per subscription + unique(tenant_id, period_start)
 * (an existing statement for the period is never recomputed). Testing spaces and canceled
 * subscriptions are skipped.
 *
 * @return Statement[] statements created
 */
class CloseSubscriptionPeriod extends UseCase
{
    public int $timeout = 300;

    public function __construct(public readonly string $subscriptionId, public readonly ?string $today = null) {}

    public function execute(): array
    {
        BillingLog::ensureTrace();
        $lock = Cache::lock("innertia:billing:close:{$this->subscriptionId}", $this->timeout);

        if (! $lock->get()) {
            BillingLog::info('close skipped: locked', ['subscription_id' => $this->subscriptionId]);

            return [];
        }

        try {
            $created = [];
            $today = $this->today ? BillingClock::date($this->today) : BillingClock::today();

            for ($guard = 0; $guard < 36; $guard++) {
                $subscription = Subscription::query()->with('plan.meters')->find($this->subscriptionId);
                if (! $subscription || $subscription->status === SubscriptionStatus::Canceled || $subscription->tenant?->isTesting()) {
                    break;
                }
                if ($subscription->current_period_end->format('Y-m-d') >= $today->format('Y-m-d')) {
                    break; // period still running
                }

                $statement = $this->closeOne($subscription);
                if ($statement) {
                    $created[] = $statement;
                }
            }

            return $created;
        } finally {
            $lock->release();
        }
    }

    private function closeOne(Subscription $subscription): ?Statement
    {
        $start = $subscription->current_period_start->format('Y-m-d');
        $end = $subscription->current_period_end->format('Y-m-d');
        $trialing = $subscription->status === SubscriptionStatus::Trialing;
        $plan = $subscription->plan;

        $statement = DB::transaction(function () use ($subscription, $start, $end, $trialing, $plan) {
            $statement = null;

            if (! $trialing && ! Statement::query()->where('tenant_id', $subscription->tenant_id)->where('period_start', $start)->exists()) {
                $result = app(StatementCalculator::class)->calculate($subscription, $plan, $start, $end);

                $statement = Statement::create([
                    'tenant_id'       => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'plan_id'         => $plan->id,
                    'period_start'    => $start,
                    'period_end'      => $end,
                    'currency'        => $plan->currency,
                    'tax_rate'        => $result['tax_rate'],
                    'subtotal'        => $result['subtotal'],
                    'tax'             => $result['tax'],
                    'total'           => $result['total'],
                    'trace_id'        => traceId() ?: null,
                ]);

                foreach ($result['lines'] as $i => $line) {
                    $statement->lines()->create($line + ['position' => $i]);
                }

                foreach (array_chunk($result['usage_ids'], 500) as $ids) {
                    UsageRecord::query()->whereIn('id', $ids)->whereNull('statement_id')->update(['statement_id' => $statement->id]);
                }
                if ($result['adjustment_ids'] !== []) {
                    Adjustment::query()->whereIn('id', $result['adjustment_ids'])->update(['consumed_at' => now()]);
                }

                (new AssembleInvoice($statement))->execute();
            }

            // Advance: the next period starts the day after; the pending plan rules from it.
            $nextPlan = $subscription->pending_plan_id ? (Plan::find($subscription->pending_plan_id) ?? $plan) : $plan;
            $nextStart = BillingClock::date($end)->addDay();
            $subscription->update([
                'plan_id'              => $nextPlan->id,
                'pending_plan_id'      => null,
                'current_period_start' => $nextStart->format('Y-m-d'),
                'current_period_end'   => BillingClock::periodEnd($nextStart, $nextPlan->interval->months())->format('Y-m-d'),
            ]);

            return $statement;
        });

        if ($trialing) {
            SubscriptionState::to($subscription->fresh(), SubscriptionStatus::Active, 'trial ended');
        }

        BillingLog::info($statement ? 'period closed' : 'trial period closed', [
            'subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id,
            'period_start' => $start, 'period_end' => $end,
            'statement_id' => $statement?->id, 'total' => $statement?->total, 'invoice_id' => $statement?->fresh()->invoice_id,
        ]);

        return $statement;
    }
}
