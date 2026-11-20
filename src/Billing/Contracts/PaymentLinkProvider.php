<?php

namespace Innertia\Billing\Contracts;

use Innertia\Billing\Models\Invoice;

/** Creates the payment link of an issued invoice (automatic payment method). */
interface PaymentLinkProvider
{
    /** @return array{url: string, charge_id?: string|null}|null null = no link (transfer only) */
    public function linkFor(Invoice $invoice): ?array;
}
