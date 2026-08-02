<?php

namespace Innertia\Payments\Enums;

enum PaymentProvider: string
{
    case Transfer    = 'transfer';
    case MercadoPago = 'mercadopago';
    case Flow        = 'flow';

    public function label(): string
    {
        return match ($this) {
            self::Transfer    => 'Transferencia',
            self::MercadoPago => 'MercadoPago',
            self::Flow        => 'Flow',
        };
    }

    /** Capacidades declaradas: 'one_off', 'subscription', 'split'. */
    public function capabilities(): array
    {
        return match ($this) {
            self::Transfer    => ['one_off'],
            self::MercadoPago => ['one_off', 'subscription', 'split'],
            self::Flow        => ['one_off'],
        };
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, $this->capabilities(), true);
    }
}
