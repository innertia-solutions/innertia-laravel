<?php

namespace Innertia\Webhooks\Support;

use Innertia\Facades\Telemetry;
use Throwable;

/** Operational telemetry of webhooks / public API (module 'webhooks' or context['module'], with trace_id; never secrets, keys nor full URLs). */
final class WebhookTelemetry
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
            $module = (string) ($context['module'] ?? 'webhooks');
            Telemetry::tag('module', $module);
            Telemetry::log('operational', $level, $message, ['module' => $module, 'trace_id' => traceId()] + $context);
        } catch (Throwable) {
            // Telemetry never breaks a delivery.
        }
    }
}
