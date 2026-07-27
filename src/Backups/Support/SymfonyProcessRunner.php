<?php

namespace Innertia\Backups\Support;

use Innertia\Backups\Contracts\ProcessRunner;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, array $env = [], int $timeout = 600): ProcessResult
    {
        $process = new Process($command, env: $env, timeout: $timeout);
        $process->run();

        return new ProcessResult(
            exitCode: $process->getExitCode() ?? 1,
            errorOutput: $process->getErrorOutput(),
        );
    }
}
