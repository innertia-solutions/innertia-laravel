<?php

namespace Innertia\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Innertia\Payments\Models\Charge;

class PaymentPaid
{
    use Dispatchable;

    public function __construct(public readonly Charge $charge) {}
}
