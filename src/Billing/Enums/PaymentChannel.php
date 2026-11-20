<?php

namespace Innertia\Billing\Enums;

/** Channel of a received payment (billing_payments.method). */
enum PaymentChannel: string
{
    case Transfer = 'transfer';
    case Link     = 'link';
    case Manual   = 'manual';
    case Credit   = 'credit';
}
