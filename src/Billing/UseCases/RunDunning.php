<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\SubscriptionState;
use Innertia\Platform\Contracts\UseCase;

/**
 * Daily dunning (innertia:billing:dunning) and re-evaluation after a payment.
 *
 *   1. issued invoices past due_at → overdue
 *   2. per subscription (active / past_due / suspended): the space's overdue invoices are its own
 *      (per_space) plus the consolidated ones that include a statement of the space.
 *        none                              → active (unless suspended by hand)
 *        oldest overdue ≤ grace_days old   → past_due
 *        oldest overdue > grace_days old   → suspended (read-only space)
 *   Trialing and canceled subscriptions are not touched; platform is never blocked.
 *
 * @return array{overdue: int, changed: int}
 */
class RunDunning extends UseCase
{
    public int $timeout = 600;

    public function __construct(public readonly ?string $clientId = null, public readonly bool $markOverdue = true) {}

    public function execute(): array
    {
        BillingLog::ensureTrace();
        $overdue = 0;

        if ($this->markOverdue) {
            $due = Invoice::query()->where('status', InvoiceStatus::Issued->value)->where('due_at', '<', now())
                ->when($this->clientId, fn ($q) => $q->where('client_id', $this->clientId))->get();
            foreach ($due as $invoice) {
                $invoice->update(['status' => InvoiceStatus::Overdue]);
                $invoice->log('overdue', ['due_at' => $invoice->due_at?->toIso8601String()]);
                $overdue++;
            }
        }

        $subscriptions = Subscription::query()
            ->with('tenant.client')
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value])
            ->when($this->clientId, fn ($q) => $q->whereIn('tenant_id', $this->tenantIdsOf($this->clientId)))
            ->get();

        $changed = 0;
        foreach ($subscriptions as $subscription) {
            try {
                $changed += $this->evaluate($subscription) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
                BillingLog::error('dunning failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }

        if ($this->markOverdue) {
            BillingLog::info('dunning finished', ['overdue' => $overdue, 'changed' => $changed]);
        }

        return ['overdue' => $overdue, 'changed' => $changed];
    }

    private function evaluate(Subscription $subscription): bool
    {
        $oldestDue = $this->oldestOverdue($subscription);

        if ($oldestDue === null) {
            if ($subscription->status === SubscriptionStatus::Suspended && $subscription->suspended_manually) {
                return false;
            }

            return SubscriptionState::to($subscription, SubscriptionStatus::Active, 'no overdue invoices');
        }

        $grace = (int) ($subscription->tenant?->client?->grace_days ?? 15);
        $target = $oldestDue->copy()->addDays($grace)->isPast() ? SubscriptionStatus::Suspended : SubscriptionStatus::PastDue;

        if ($subscription->status === SubscriptionStatus::Suspended && $target === SubscriptionStatus::PastDue) {
            return false; // a suspension (by hand or by grace) is lifted only by paying
        }

        return SubscriptionState::to($subscription, $target, 'overdue invoices');
    }

    private function oldestOverdue(Subscription $subscription): ?\Carbon\CarbonInterface
    {
        $tenantId = (string) $subscription->tenant_id;
        $consolidated = Statement::query()->where('tenant_id', $tenantId)->whereNotNull('invoice_id')->pluck('invoice_id');

        $due = Invoice::query()
            ->where('status', InvoiceStatus::Overdue->value)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereIn('id', $consolidated))
            ->min('due_at');

        return $due ? \Illuminate\Support\Carbon::parse($due) : null;
    }

    private function tenantIdsOf(string $clientId): array
    {
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;

        return $model::query()->where('client_id', $clientId)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
