<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\BillingClock;
use Innertia\Billing\Support\BillingLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * Daily close (innertia:billing:close): every subscription whose period ended before today
 * (billing time zone). A failure in one subscription is logged and does not stop the others.
 *
 * @return array{subscriptions: int, statements: int, failed: int}
 */
class CloseBillingPeriods extends UseCase
{
    public int $timeout = 1800;

    public function __construct(public readonly ?string $today = null) {}

    public function execute(): array
    {
        BillingLog::ensureTrace();
        $today = $this->today ? BillingClock::date($this->today) : BillingClock::today();

        $ids = Subscription::query()
            ->where('status', '!=', SubscriptionStatus::Canceled->value)
            ->where('current_period_end', '<', $today->format('Y-m-d'))
            ->pluck('id');

        $statements = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $statements += count((new CloseSubscriptionPeriod((string) $id, $today->format('Y-m-d')))->execute());
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                BillingLog::error('period close failed', ['subscription_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $summary = ['subscriptions' => $ids->count(), 'statements' => $statements, 'failed' => $failed];
        BillingLog::info('billing close finished', $summary + ['date' => $today->format('Y-m-d')]);

        return $summary;
    }
}
