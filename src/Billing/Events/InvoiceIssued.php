<?php

namespace Innertia\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Innertia\Billing\Models\Invoice;

class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
