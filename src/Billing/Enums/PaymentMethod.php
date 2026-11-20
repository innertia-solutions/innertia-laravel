<?php

namespace Innertia\Billing\Enums;

/** How a subscription pays: `automatic` = payment link at issue; `invoice` = payment registered by hand. */
enum PaymentMethod: string
{
    case Automatic = 'automatic';
    case Invoice   = 'invoice';
}
