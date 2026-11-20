<?php

namespace Innertia\Billing\Support;

/** Formatting of minor-unit integers (CLP has no decimals: $ 12.345). */
final class Money
{
    public static function format(int $amount, string $currency = 'CLP'): string
    {
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);

        return $currency === 'CLP'
            ? $sign . '$ ' . number_format($abs, 0, ',', '.')
            : $sign . $currency . ' ' . number_format($abs / 100, 2, ',', '.');
    }

    /** Line rounding: half up to the minor unit. */
    public static function round(float $amount): int
    {
        return (int) round($amount, 0, PHP_ROUND_HALF_UP);
    }
}
