<?php

namespace Innertia\Backups\UseCases;

use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Models\Backup;
use Innertia\Platform\Contracts\UseCase;

class RestoreBackup extends UseCase
{
    public function __construct(public readonly Backup $backup) {}

    public function execute(): void
    {
        // 1) Respaldo de seguridad automático ANTES de restaurar (síncrono).
        $safety = Backup::create([
            'disk'      => config('innertia.backups.disk', 'backups'),
            'driver'    => $this->backup->driver,
            'mode'      => $this->backup->mode,
            'is_safety' => true,
            'status'    => 'running',
        ]);
        (new RunBackup($safety))->execute();

        // 2) Restaurar el respaldo objetivo.
        app(BackupDriver::class)->restore($this->backup);
    }
}
