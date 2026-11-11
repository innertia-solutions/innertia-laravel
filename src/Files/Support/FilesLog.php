<?php

namespace Innertia\Files\Support;

use Innertia\Facades\Telemetry;

/** Log operacional del módulo Files que nunca rompe la operación que lo llama. */
final class FilesLog
{
    public static function warning(string $message, array $context = []): void
    {
        try {
            Telemetry::log('operational', 'warning', $message, ['module' => 'files'] + $context);
        } catch (\Throwable) {
            // la observabilidad nunca debe tumbar una operación de archivos
        }
    }
}
