<?php

namespace Innertia\Notifications\Support;

use Innertia\Facades\Telemetry;
use Throwable;

/** Operational telemetry of the Notifier (module 'notifications', with trace_id). */
final class NotifyLog
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
            Telemetry::tag('module', 'notifications');
            Telemetry::log('operational', $level, $message, ['module' => 'notifications', 'trace_id' => traceId()] + $context);
        } catch (Throwable) {
            // Telemetry never breaks a delivery.
        }
    }
}
