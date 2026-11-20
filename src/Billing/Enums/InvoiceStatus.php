<?php

namespace Innertia\Billing\Enums;

enum InvoiceStatus: string
{
    case Draft   = 'draft';
    case Issued  = 'issued';
    case Paid    = 'paid';
    case Overdue = 'overdue';
    case Void    = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft   => 'Borrador',
            self::Issued  => 'Emitida',
            self::Paid    => 'Pagada',
            self::Overdue => 'Vencida',
            self::Void    => 'Anulada',
        };
    }

    /** Issued and not settled. */
    public function isOpen(): bool
    {
        return $this === self::Issued || $this === self::Overdue;
    }
}
