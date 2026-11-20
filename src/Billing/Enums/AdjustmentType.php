<?php

namespace Innertia\Billing\Enums;

enum AdjustmentType: string
{
    case Discount  = 'discount';
    case Surcharge = 'surcharge';
    case OneOff    = 'one_off';
    case Credit    = 'credit';

    /** Discounts and credits reduce the statement. */
    public function sign(): int
    {
        return in_array($this, [self::Discount, self::Credit], true) ? -1 : 1;
    }
}
