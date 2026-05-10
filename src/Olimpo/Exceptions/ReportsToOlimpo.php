<?php

namespace Innertia\Olimpo\Exceptions;

use Throwable;

/**
 * Agrega este trait al Handler de tu app:
 *
 *   use \Innertia\Olimpo\Exceptions\ReportsToOlimpo;
 *
 * Luego en register():
 *
 *   $this->reportable(fn (Throwable $e) => $this->reportToOlimpo($e));
 */
trait ReportsToOlimpo
{
    protected function reportToOlimpo(Throwable $e, array $context = []): void
    {
        OlimpoExceptionReporter::report($e, $context);
    }
}
