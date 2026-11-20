<?php

namespace Innertia\Billing\Support;

use Illuminate\Support\Str;
use Innertia\Facades\Telemetry;
use Throwable;

/** Operational telemetry of billing (module 'billing', with trace_id). Never breaks a flow. */
final class BillingLog
{
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** Ensures a trace_id for console/queue runs (HTTP requests already have one). */
    public static function ensureTrace(): string
    {
        $id = function_exists('traceId') ? traceId() : '';
        if ($id === '') {
            $id = (string) Str::ulid();
            app()->instance('trace_id', $id);
        }

        return $id;
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            Telemetry::tag('module', 'billing');
            Telemetry::log('operational', $level, $message, ['module' => 'billing', 'trace_id' => traceId()] + $context);
        } catch (Throwable) {
            // Telemetry never breaks billing.
        }
    }
}
