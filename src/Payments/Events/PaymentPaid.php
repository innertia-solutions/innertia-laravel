<?php

namespace Innertia\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Models\Payment;

class PaymentPaid
{
    use Dispatchable;

    public function __construct(
        public readonly Charge $charge,
        public readonly ?Payment $payment = null,
    ) {}
}
