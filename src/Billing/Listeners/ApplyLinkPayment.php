<?php

namespace Innertia\Billing\Listeners;

use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\UseCases\RegisterPayment;
use Innertia\Payments\Events\PaymentPaid;

/** A paid Innertia\Payments charge whose billable is an invoice → billing payment applied to it (idempotent by charge). */
class ApplyLinkPayment
{
    public function handle(PaymentPaid $paid): void
    {
        $charge = $paid->charge;
        if ($charge->billable_type !== (new Invoice)->getMorphClass()) {
            return;
        }

        $invoice = Invoice::find($charge->billable_id);
        if (! $invoice) {
            return;
        }

        (new RegisterPayment(
            clientId: (string) $invoice->client_id,
            amount: (int) ($paid->payment?->amount ?? $charge->amount),
            method: PaymentChannel::Link,
            reference: $paid->payment?->external_reference,
            invoiceId: $invoice->status->isOpen() ? $invoice->id : null,
            chargeId: (string) $charge->id,
            createdBy: 'payment-link',
        ))->execute();
    }
}
