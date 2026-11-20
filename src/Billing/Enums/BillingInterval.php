<?php

namespace Innertia\Billing\Enums;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual  = 'annual';

    public function months(): int
    {
        return $this === self::Annual ? 12 : 1;
    }
}
