<?php

namespace Innertia\Billing\Support;

use Illuminate\Support\Collection;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Models\Allocation;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Models\Statement;
use Innertia\Saas\Models\Client;

/**
 * Estado de cuenta: charges (issued invoices), credits (payments, credit notes, voids) and running
 * balance of a client — or of one space (backoffice: its invoices, own or consolidated, and what was
 * applied to them) — plus the aging of the open debt (not due, 1–30, 31–60, 61–90, 90+ days past due).
 * Positive balance = owed; negative = credit in favor of the client.
 */
final class AccountStatement
{
    public const BUCKETS = ['current' => 'Por vencer', 'd0_30' => '0–30 días', 'd31_60' => '31–60 días', 'd61_90' => '61–90 días', 'd90_plus' => 'Más de 90 días'];

    public static function build(string $clientId, ?string $from = null, ?string $to = null, ?string $tenantId = null): array
    {
        $client = Client::query()->findOrFail($clientId);
        $tz = BillingClock::timezone();
        $local = fn ($ts) => $ts ? $ts->copy()->timezone($tz)->format('Y-m-d') : null;

        $invoices = self::invoices($clientId, $tenantId)->where('status', '!=', InvoiceStatus::Draft->value)->whereNotNull('issued_at')->get();
        $parts = $tenantId ? Statement::query()->where('tenant_id', $tenantId)->whereIn('invoice_id', $invoices->pluck('id'))
            ->selectRaw('invoice_id, SUM(total) as total')->groupBy('invoice_id')->pluck('total', 'invoice_id') : collect();

        $movements = [];
        foreach ($invoices as $invoice) {
            $movements[] = [
                'date' => $local($invoice->issued_at), 'order' => 0, 'type' => 'invoice', 'reference' => $invoice->displayNumber(),
                'description' => 'Factura ' . $invoice->displayNumber() . ' · período ' . $invoice->period_start->format('d-m-Y') . ' al ' . $invoice->period_end->format('d-m-Y')
                    . ($tenantId && $invoice->isConsolidated() ? ' (factura conjunta; parte del espacio ' . Money::format((int) ($parts[$invoice->id] ?? 0), $invoice->currency) . ')' : ''),
                'charge' => $invoice->total, 'credit' => 0, 'invoice_id' => $invoice->id, 'payment_id' => null,
            ];
            if ($invoice->status === InvoiceStatus::Void) {
                $movements[] = [
                    'date' => $local($invoice->voided_at ?? $invoice->updated_at), 'order' => 2, 'type' => 'void', 'reference' => $invoice->displayNumber(),
                    'description' => 'Anulación factura ' . $invoice->displayNumber() . ($invoice->void_reason ? " · {$invoice->void_reason}" : ''),
                    'charge' => 0, 'credit' => $invoice->total, 'invoice_id' => $invoice->id, 'payment_id' => null,
                ];
            }
        }

        if ($tenantId === null) {
            foreach (Payment::query()->where('client_id', $clientId)->get() as $payment) {
                $isNote = $payment->method === PaymentChannel::Credit;
                $movements[] = [
                    'date' => $local($payment->received_at), 'order' => 1, 'type' => $isNote ? 'credit_note' : 'payment',
                    'reference' => $payment->reference,
                    'description' => $isNote ? 'Nota de crédito ' . $payment->reference : 'Pago ' . self::channel($payment->method) . ($payment->reference ? " · {$payment->reference}" : ''),
                    'charge' => 0, 'credit' => $payment->amount, 'invoice_id' => null, 'payment_id' => $payment->id,
                ];
            }
        } else {
            $allocations = Allocation::query()->whereIn('invoice_id', $invoices->pluck('id'))->get();
            $payments = Payment::query()->whereIn('id', $allocations->pluck('payment_id')->unique())->get()->keyBy('id');
            foreach ($allocations as $allocation) {
                $payment = $payments->get($allocation->payment_id);
                $isNote = $payment?->method === PaymentChannel::Credit;
                $movements[] = [
                    'date' => $local($payment?->received_at ?? $allocation->created_at), 'order' => 1, 'type' => $isNote ? 'credit_note' : 'payment',
                    'reference' => $payment?->reference,
                    'description' => ($isNote ? 'Nota de crédito ' . $payment?->reference : 'Pago ' . self::channel($payment?->method))
                        . ' aplicado a ' . $invoices->firstWhere('id', $allocation->invoice_id)?->displayNumber(),
                    'charge' => 0, 'credit' => $allocation->amount, 'invoice_id' => $allocation->invoice_id, 'payment_id' => $allocation->payment_id,
                ];
            }
        }

        usort($movements, fn ($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

        $opening = 0;
        $rows = [];
        $balance = 0;
        foreach ($movements as $m) {
            if ($from && $m['date'] < $from) {
                $opening += $m['charge'] - $m['credit'];
                $balance = $opening;
                continue;
            }
            if ($to && $m['date'] > $to) {
                continue;
            }
            $balance += $m['charge'] - $m['credit'];
            unset($m['order']);
            $rows[] = $m + ['balance' => $balance];
        }

        $open = $invoices->filter(fn (Invoice $i) => $i->status->isOpen() && $i->balance > 0);

        return [
            'client'           => ['id' => (string) $client->id, 'name' => $client->name, 'tax_id' => $client->tax_id],
            'tenant_id'        => $tenantId,
            'from'             => $from,
            'to'               => $to,
            'currency'         => $invoices->first()?->currency ?? config('innertia.billing.currency', 'CLP'),
            'opening_balance'  => $opening,
            'movements'        => $rows,
            'totals'           => ['charges' => (int) array_sum(array_column($rows, 'charge')), 'credits' => (int) array_sum(array_column($rows, 'credit'))],
            'closing_balance'  => $balance,
            'open_balance'     => (int) $open->sum('balance'),
            'credit_available' => $tenantId ? null : (int) Payment::query()->where('client_id', $clientId)->sum('unallocated_amount'),
            'aging'            => self::aging($open),
            'open_invoices'    => $open->sortBy('due_at')->map(fn (Invoice $i) => [
                'id' => $i->id, 'display_number' => $i->displayNumber(), 'status' => $i->status->value, 'total' => $i->total,
                'balance' => $i->balance, 'due_at' => $i->due_at?->toIso8601String(), 'days_overdue' => self::daysOverdue($i),
                'bucket' => self::bucket(self::daysOverdue($i)),
            ])->values()->all(),
        ];
    }

    /** @return array<string, int> bucket => balance (+ total) */
    public static function aging(Collection $openInvoices): array
    {
        $out = array_fill_keys(array_keys(self::BUCKETS), 0);
        foreach ($openInvoices as $invoice) {
            $out[self::bucket(self::daysOverdue($invoice))] += $invoice->balance;
        }

        return $out + ['total' => array_sum($out)];
    }

    public static function daysOverdue(Invoice $invoice): int
    {
        $due = $invoice->dueDate();

        return $due ? (int) max(0, $due->diffInDays(BillingClock::today(), false)) : 0;
    }

    public static function bucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0  => 'current',
            $daysOverdue <= 30 => 'd0_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default            => 'd90_plus',
        };
    }

    /** Invoices of a client, or of one space (own + consolidated ones that include it). */
    public static function invoices(string $clientId, ?string $tenantId = null)
    {
        return Invoice::query()->where('client_id', $clientId)
            ->when($tenantId, fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', $tenantId)
                ->orWhereIn('id', Statement::query()->where('tenant_id', $tenantId)->whereNotNull('invoice_id')->select('invoice_id'))));
    }

    private static function channel(?PaymentChannel $channel): string
    {
        return match ($channel) {
            PaymentChannel::Link     => 'en línea',
            PaymentChannel::Transfer => 'por transferencia',
            PaymentChannel::Credit   => 'con saldo a favor',
            default                  => 'manual',
        };
    }
}
