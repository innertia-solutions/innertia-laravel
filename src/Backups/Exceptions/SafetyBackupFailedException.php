<?php

namespace Innertia\Backups\Exceptions;

use RuntimeException;

class SafetyBackupFailedException extends RuntimeException
{
    public static function for(string $error): self
    {
        return new self("Restore aborted: the automatic safety backup failed before restoring. Error: {$error}");
    }
}
