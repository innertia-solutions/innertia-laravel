<?php

namespace Innertia\Billing\Support;

use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\ClientTerms;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\LateFee;
use Innertia\Billing\Models\Statement;

/**
 * Late fee interest of one space at a period close (pure: writes nothing).
 *
 * For every overdue invoice of the space (its own per_space invoices, or the consolidated invoices that
 * include a statement of the space — then only the space's share of the balance), interest runs from the
 * day after the due date (or the day after the last charged range) up to the period end:
 *
 *   amount = round(balance × share × rate% per day × days)
 *   rate per day = clients.late_fee_percent / 30 (basis monthly) or late_fee_percent (basis daily)
 *
 * Capped by late_fee_cap_percent of the invoice total (share included), minus what was already charged.
 * Nothing when the client is exempt, the percent is 0 or the invoice's late fees were waived.
 *
 * @return array<int, array{invoice_id: string, display_number: ?string, from: string, to: string, days: int, base: int, rate: float, amount: int}>
 */
final class LateFeeCalculator
{
    public static function forSpace(string $tenantId, string $periodEnd): array
    {
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
        $client = $model::query()->find($tenantId)?->client;
        if (! $client) {
            return [];
        }

        $percent = (float) ($client->late_fee_percent ?? 0);
        $terms = ClientTerms::for((string) $client->id);
        if ($percent <= 0 || $terms->late_fee_exempt) {
            return [];
        }
        $dailyRate = $terms->late_fee_basis === 'daily' ? $percent : $percent / 30;

        $consolidatedIds = Statement::query()->where('tenant_id', $tenantId)->whereNotNull('invoice_id')->pluck('invoice_id');
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Overdue->value)
            ->whereNull('late_fees_waived_at')
            ->where('balance', '>', 0)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhere(fn ($w) => $w->whereNull('tenant_id')->whereIn('id', $consolidatedIds)))
            ->orderBy('due_at')
            ->get();

        $fees = [];
        foreach ($invoices as $invoice) {
            $share = self::share($invoice, $tenantId);
            if ($share <= 0) {
                continue;
            }

            $charged = LateFee::query()->where('invoice_id', $invoice->id)->where('tenant_id', $tenantId);
            $lastTo = (clone $charged)->max('to_date');
            $from = $lastTo
                ? BillingClock::date((string) $lastTo)->addDay()
                : $invoice->dueDate()->addDay();
            $to = BillingClock::date($periodEnd);
            if ($from->greaterThan($to)) {
                continue;
            }

            $days = (int) $from->diffInDays($to) + 1;
            $base = Money::round($invoice->balance * $share);
            $amount = Money::round($base * $dailyRate / 100 * $days);

            if ($terms->late_fee_cap_percent !== null) {
                $cap = Money::round($invoice->total * $share * $terms->late_fee_cap_percent / 100);
                $already = (int) (clone $charged)->whereNull('waived_at')->sum('amount');
                $amount = max(0, min($amount, $cap - $already));
            }
            if ($amount <= 0) {
                continue;
            }

            $fees[] = [
                'invoice_id'     => $invoice->id,
                'display_number' => $invoice->displayNumber(),
                'from'           => $from->format('Y-m-d'),
                'to'             => $to->format('Y-m-d'),
                'days'           => $days,
                'base'           => $base,
                'rate'           => round($dailyRate, 6),
                'amount'         => $amount,
            ];
        }

        return $fees;
    }

    /** Fraction of the invoice that belongs to the space (1 for its own invoices). */
    public static function share(Invoice $invoice, string $tenantId): float
    {
        if (! $invoice->isConsolidated()) {
            return (string) $invoice->tenant_id === $tenantId ? 1.0 : 0.0;
        }
        if ($invoice->total <= 0) {
            return 0.0;
        }
        $part = (int) Statement::query()->where('invoice_id', $invoice->id)->where('tenant_id', $tenantId)->sum('total');

        return $part / $invoice->total;
    }
}
