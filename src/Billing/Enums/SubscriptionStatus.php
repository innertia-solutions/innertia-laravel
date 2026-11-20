<?php

namespace Innertia\Billing\Enums;

enum SubscriptionStatus: string
{
    case Trialing  = 'trialing';
    case Active    = 'active';
    case PastDue   = 'past_due';
    case Suspended = 'suspended';
    case Canceled  = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing  => 'En prueba',
            self::Active    => 'Al día',
            self::PastDue   => 'Con deuda vencida',
            self::Suspended => 'Suspendido',
            self::Canceled  => 'Cancelada',
        };
    }

    /** Statuses that are billed at the period close. */
    public function isBillable(): bool
    {
        return in_array($this, [self::Active, self::PastDue, self::Suspended], true);
    }
}
