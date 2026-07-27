<?php

namespace Innertia\Backups\Support;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $errorOutput = '',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }
}
