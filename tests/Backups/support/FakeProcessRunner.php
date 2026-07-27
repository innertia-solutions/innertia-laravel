<?php

use Innertia\Backups\Contracts\ProcessRunner;
use Innertia\Backups\Support\ProcessResult;

class FakeProcessRunner implements ProcessRunner
{
    /** @var array<int,array{command:array,env:array}> */
    public array $calls = [];
    public int $exitCode = 0;
    public string $errorOutput = '';
    public string $artifactContents = 'FAKE_PG_DUMP_ARTIFACT';

    public function run(array $command, array $env = [], int $timeout = 600): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'env' => $env];

        // Si el comando incluye "-f <path>" (pg_dump), escribe el artefacto dummy.
        $fIndex = array_search('-f', $command, true);
        if ($this->exitCode === 0 && $fIndex !== false && isset($command[$fIndex + 1])) {
            file_put_contents($command[$fIndex + 1], $this->artifactContents);
        }

        return new ProcessResult($this->exitCode, $this->errorOutput);
    }

    public function lastCommand(): array
    {
        return end($this->calls)['command'] ?? [];
    }
}
