<?php

namespace Innertia\Backups\Contracts;

use Innertia\Backups\Support\ProcessResult;

interface ProcessRunner
{
    /**
     * Ejecuta un binario. $command es argv (array); $env variables de entorno extra.
     * $timeout en segundos.
     */
    public function run(array $command, array $env = [], int $timeout = 600): ProcessResult;
}
