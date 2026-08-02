<?php

namespace Innertia\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Models\Payment;

class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Charge $charge,
        public readonly ?Payment $payment = null,
    ) {}
}
