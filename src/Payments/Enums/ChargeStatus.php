<?php

namespace Innertia\Payments\Enums;

enum ChargeStatus: string
{
    case Pending              = 'pending';
    case AwaitingConfirmation = 'awaiting_confirmation'; // solo transferencia
    case Paid                 = 'paid';
    case Failed               = 'failed';
    case Refunded             = 'refunded';              // terminal

    /** Transiciones válidas de la máquina de estados del Charge. */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending              => in_array($to, [self::AwaitingConfirmation, self::Paid, self::Failed], true),
            self::AwaitingConfirmation => in_array($to, [self::Paid, self::Pending, self::Failed], true),
            self::Paid                 => $to === self::Refunded,
            self::Failed               => $to === self::Pending, // reintento
            self::Refunded             => false,                 // terminal
        };
    }
}
