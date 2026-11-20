<?php

namespace Innertia\Billing\Support;

use Innertia\Billing\Contracts\PaymentLinkProvider;
use Innertia\Billing\Models\Invoice;
use Innertia\Facades\Innertia;
use Innertia\Payments\Enums\PaymentProvider;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\PaymentManager;

/**
 * Payment link through Innertia\Payments (Flow / MercadoPago) with the PLATFORM credentials:
 * the charge is created with no active tenant, so Settings resolve at platform level.
 * The charge's billable is the invoice; the PaymentPaid listener applies the payment.
 */
class PaymentsLinkProvider implements PaymentLinkProvider
{
    public function __construct(private readonly string $provider) {}

    public function linkFor(Invoice $invoice): ?array
    {
        $previous = Innertia::tenant();
        Innertia::deactivate();

        try {
            $charge = Charge::query()->where('billable_type', $invoice->getMorphClass())->where('billable_id', $invoice->id)
                ->whereIn('status', ['pending', 'awaiting_confirmation'])->first();

            $charge ??= Charge::create([
                'tenant_id'     => $invoice->tenant_id,
                'billable_type' => $invoice->getMorphClass(),
                'billable_id'   => $invoice->id,
                'amount'        => $invoice->balance,
                'currency'      => $invoice->currency,
                'status'        => 'pending',
                'metadata'      => [
                    'concept'     => 'Factura ' . $invoice->displayNumber(),
                    'payer_email' => $invoice->billing_data['billing_email'] ?? null,
                    'source'      => 'billing',
                ],
                'due_at'        => $invoice->due_at,
            ]);

            $payment = app(PaymentManager::class)->for(PaymentProvider::from($this->provider))->createPayment($charge);

            return $payment->link ? ['url' => $payment->link, 'charge_id' => (string) $charge->id] : null;
        } finally {
            if ($previous) {
                app(\Innertia\Saas\TenantContext::class)->set($previous);
            }
        }
    }
}
