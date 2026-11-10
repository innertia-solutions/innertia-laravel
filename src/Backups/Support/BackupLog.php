<?php

namespace Innertia\Backups\Support;

use Innertia\Facades\Telemetry;
use Throwable;

/**
 * Log de respaldos en el panel de observabilidad (canal operational, con el
 * trace_id del proceso). La telemetría nunca debe romper un respaldo.
 */
final class BackupLog
{
    public static function write(string $level, string $message, array $context = []): void
    {
        try {
            Telemetry::tag('module', 'backups');
            Telemetry::log('operational', $level, $message, ['module' => 'backups'] + $context);
        } catch (Throwable) {
            // telemetry_events ausente o deshabilitada: se ignora.
        }
    }
}
