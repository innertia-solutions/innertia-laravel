<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Events\InvoicePaid;
use Innertia\Billing\Models\Allocation;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Support\BillingLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * Applies the unallocated part of a payment to open invoices: the given one, or the client's
 * open invoices oldest-due first. A fully paid invoice becomes `paid`; the remainder stays as
 * client credit (unallocated_amount). Then the client's subscriptions are re-evaluated
 * (paying the last overdue invoice reactivates the space).
 */
class ApplyPayment extends UseCase
{
    public function __construct(public readonly Payment $payment, public readonly ?Invoice $invoice = null) {}

    public function execute(): Payment
    {
        $payment = $this->payment->fresh();

        $invoices = $this->invoice
            ? collect([$this->invoice->fresh()])
            : Invoice::query()->where('client_id', $payment->client_id)
                ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])
                ->where('balance', '>', 0)
                ->orderBy('due_at')->orderBy('number')->get();

        foreach ($invoices as $invoice) {
            if ($payment->fresh()->unallocated_amount <= 0) {
                break;
            }
            if ($invoice->status->isOpen()) {
                self::allocate($payment, $invoice);
            }
        }

        (new RunDunning(clientId: $payment->client_id, markOverdue: false))->execute();

        return $payment->fresh(['allocations']);
    }

    /** Moves min(unallocated, balance) from the payment to the invoice. Returns the amount. */
    public static function allocate(Payment $payment, Invoice $invoice, string $event = 'payment_applied'): int
    {
        $paid = DB::transaction(function () use ($payment, $invoice, $event) {
            $payment = Payment::query()->lockForUpdate()->find($payment->id);
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);

            $amount = min($payment->unallocated_amount, $invoice->balance);
            if ($amount <= 0) {
                return 0;
            }

            Allocation::create([
                'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount,
                'created_by' => $payment->created_by, 'created_at' => now(),
            ]);
            $payment->update(['unallocated_amount' => $payment->unallocated_amount - $amount]);

            $balance = $invoice->balance - $amount;
            $invoice->update([
                'balance' => $balance,
                'status'  => $balance <= 0 ? InvoiceStatus::Paid : $invoice->status,
                'paid_at' => $balance <= 0 ? now() : null,
            ]);
            $invoice->log($event, ['payment_id' => $payment->id, 'amount' => $amount, 'balance' => $balance]);

            return $amount;
        });

        if ($paid > 0) {
            $invoice = $invoice->fresh();
            BillingLog::info('payment applied', ['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount' => $paid, 'balance' => $invoice->balance]);
            \Innertia\Billing\Support\BillingNotices::paymentReceived($invoice, $paid);
            if ($invoice->status === InvoiceStatus::Paid) {
                event(new InvoicePaid($invoice));
            }
        }

        return $paid;
    }
}
