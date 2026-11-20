<?php

namespace Innertia\Billing\Support;

use Innertia\Billing\Models\Invoice;
use Innertia\Documents\InnertiaDocument;
use Innertia\Mail\Branding;

/** PDF of an invoice with the platform brand (view innertia::billing.invoice, overridable via vendor views). */
class InvoicePdf extends InnertiaDocument
{
    public function __construct(private readonly Invoice $invoice) {}

    public function view(): string
    {
        return (string) config('innertia.billing.invoice_view', 'innertia::billing.invoice');
    }

    public function data(): array
    {
        $invoice = $this->invoice->loadMissing(['statements.lines', 'statements.tenant', 'client']);
        $tz = BillingClock::timezone();

        return [
            'invoice'      => $invoice,
            'brand'        => Branding::platform(),
            'billing'      => $invoice->billing_data ?? ($invoice->tenant_id ? $invoice->tenant?->billingData() : $invoice->client?->billingData()) ?? [],
            'number'       => $invoice->displayNumber() ?? 'Borrador',
            'issuedAt'     => $invoice->issued_at?->timezone($tz)->format('d-m-Y'),
            'dueAt'        => $invoice->due_at?->timezone($tz)->format('d-m-Y'),
            'money'        => fn (int $amount) => Money::format($amount, $invoice->currency),
            'instructions' => BillingSettings::get('transfer_instructions'),
            'issuer'       => BillingSettings::issuer(),
        ];
    }

    public function filename(): string
    {
        return 'factura-' . ($this->invoice->displayNumber() ?? $this->invoice->id) . '.pdf';
    }
}
