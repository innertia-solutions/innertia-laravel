<?php

namespace Innertia\Billing\Support;

use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\Allocation;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;

/** Reversal of allocations (void, reissue overflow, wrong reconciliation). Always inside a transaction. */
final class Allocations
{
    /**
     * Releases every allocation of an invoice back to its payments (they become client credit).
     *
     * @return int amount released
     */
    public static function releaseInvoice(Invoice $invoice, string $event = 'payment_released'): int
    {
        return DB::transaction(function () use ($invoice, $event) {
            $released = 0;
            foreach (Allocation::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get() as $allocation) {
                Payment::query()->whereKey($allocation->payment_id)->increment('unallocated_amount', $allocation->amount);
                $invoice->log($event, ['payment_id' => $allocation->payment_id, 'amount' => $allocation->amount]);
                $released += $allocation->amount;
                $allocation->delete();
            }

            return $released;
        });
    }

    /**
     * Undoes a payment: every allocation is removed and the invoices reopen (issued, or overdue when past due).
     *
     * @return string[] invoice ids touched
     */
    public static function reversePayment(Payment $payment, string $event = 'payment_reversed'): array
    {
        return DB::transaction(function () use ($payment, $event) {
            $touched = [];
            foreach (Allocation::query()->where('payment_id', $payment->id)->lockForUpdate()->get() as $allocation) {
                $invoice = Invoice::query()->lockForUpdate()->find($allocation->invoice_id);
                if ($invoice && $invoice->status !== InvoiceStatus::Void) {
                    $balance = $invoice->balance + $allocation->amount;
                    $invoice->update([
                        'balance' => $balance,
                        'status'  => $invoice->status === InvoiceStatus::Paid
                            ? ($invoice->due_at && $invoice->due_at->isPast() ? InvoiceStatus::Overdue : InvoiceStatus::Issued)
                            : $invoice->status,
                        'paid_at' => null,
                    ]);
                    $invoice->log($event, ['payment_id' => $payment->id, 'amount' => $allocation->amount, 'balance' => $balance]);
                    $touched[] = $invoice->id;
                }
                $allocation->delete();
            }

            return $touched;
        });
    }
}
