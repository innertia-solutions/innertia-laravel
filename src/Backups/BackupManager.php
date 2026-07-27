<?php

namespace Innertia\Backups;

use Illuminate\Contracts\Container\Container;
use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Drivers\AppBackupDriver;
use Innertia\Backups\Exceptions\UnsupportedBackupModeException;

class BackupManager
{
    public function __construct(private readonly Container $app) {}

    public function driver(?string $mode = null): BackupDriver
    {
        $mode ??= config('innertia.backups.mode', 'app');

        // Drivers 'saas' / 'open' están definidos en la interfaz pero diferidos:
        // caen en el default y lanzan hasta que haya un consumidor que los implemente.
        return match ($mode) {
            'app'   => $this->app->make(AppBackupDriver::class),
            default => throw UnsupportedBackupModeException::for($mode),
        };
    }
}
