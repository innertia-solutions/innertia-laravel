<?php

namespace Innertia\Billing\Support;

/** USD→CLP rate used to value AI cost (platform setting billing.usd_clp_rate; config fallback). */
final class ExchangeRate
{
    public static function usdToClp(): float
    {
        $rate = (float) BillingSettings::get('usd_clp_rate');

        return $rate > 0 ? $rate : (float) config('innertia.billing.usd_clp_rate', 950);
    }

    /** USD amount → integer minor units of the billing currency. */
    public static function toMinor(float $usd): int
    {
        return (int) round($usd * self::usdToClp());
    }
}
