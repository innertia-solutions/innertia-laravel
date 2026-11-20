<?php

namespace Innertia\Billing\Support;

use Carbon\CarbonImmutable;

/**
 * Time zone rules of billing (innertia.billing.timezone, default America/Santiago).
 * Period dates are local dates; usage timestamps are stored in the app time zone.
 */
final class BillingClock
{
    public static function timezone(): string
    {
        return (string) config('innertia.billing.timezone', 'America/Santiago');
    }

    /** Today as a local date. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function date(string|\DateTimeInterface $date): CarbonImmutable
    {
        $value = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, self::timezone());
    }

    /**
     * App-time-zone bounds [from, to) of local dates $start..$end (inclusive), for usage queries.
     *
     * @return array{0: string, 1: string}
     */
    public static function bounds(string|\DateTimeInterface $start, string|\DateTimeInterface $end): array
    {
        $app = (string) config('app.timezone', 'UTC');

        return [
            self::date($start)->setTimezone($app)->format('Y-m-d H:i:s'),
            self::date($end)->addDay()->setTimezone($app)->format('Y-m-d H:i:s'),
        ];
    }

    /** Last day of the period that starts on $start (anniversary: +N months − 1 day). */
    public static function periodEnd(string|\DateTimeInterface $start, int $months): CarbonImmutable
    {
        return self::date($start)->addMonthsNoOverflow($months)->subDay();
    }
}
