<?php

namespace Innertia\Billing\Support;

use Innertia\Billing\Contracts\PaymentLinkProvider;
use Innertia\Billing\Models\Invoice;

/** No online payment configured: invoices are paid by transfer and registered by hand. */
class NullPaymentLinkProvider implements PaymentLinkProvider
{
    public function linkFor(Invoice $invoice): ?array
    {
        return null;
    }
}
