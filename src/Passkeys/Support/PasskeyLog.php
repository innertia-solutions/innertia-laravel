<?php

namespace Innertia\Passkeys\Support;

use Innertia\Facades\Telemetry;
use Throwable;

final class PasskeyLog
{
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            Telemetry::tag('module', 'passkeys');
            Telemetry::log('operational', $level, $message, ['module' => 'passkeys', 'trace_id' => traceId()] + $context);
        } catch (Throwable) {
            // La telemetría nunca rompe un ingreso.
        }
    }
}
