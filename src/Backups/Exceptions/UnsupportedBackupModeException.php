<?php

namespace Innertia\Backups\Exceptions;

use RuntimeException;

class UnsupportedBackupModeException extends RuntimeException
{
    public static function for(string $mode): self
    {
        return new self("Backup mode [{$mode}] is not implemented yet.");
    }
}
