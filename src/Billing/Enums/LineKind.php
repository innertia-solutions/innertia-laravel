<?php

namespace Innertia\Billing\Enums;

enum LineKind: string
{
    case Base       = 'base';
    case Seats      = 'seats';
    case Meter      = 'meter';
    case Adjustment = 'adjustment';
    case LateFee    = 'late_fee';
    case LateUsage  = 'late_usage';
}
