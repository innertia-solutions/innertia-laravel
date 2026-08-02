<?php

namespace Innertia\Observability\Support;

use Innertia\Telemetry\Models\TelemetryCase;

class Criticality
{
    public static function threshold(): int
    {
        return (int) config('innertia.observability.critical_threshold', 10);
    }

    /** Un caso es crítico si está abierto y su recurrencia supera el umbral. */
    public static function isCritical(TelemetryCase $case): bool
    {
        return $case->status === 'open' && (int) $case->times_seen >= self::threshold();
    }
}
