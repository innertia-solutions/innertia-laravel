<?php

namespace Innertia\Backups\UseCases;

use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\Support\BackupLog;
use Innertia\Platform\Contracts\UseCase;
use Throwable;

class RunBackup extends UseCase
{
    public function __construct(public readonly Backup $backup) {}

    public function execute(): void
    {
        $context = ['backup_id' => $this->backup->id, 'safety' => (bool) $this->backup->is_safety];

        $this->backup->update(['status' => 'running']);
        BackupLog::write('info', 'Respaldo iniciado.', $context);

        try {
            app(BackupDriver::class)->create($this->backup);
            $this->backup->update(['status' => 'completed', 'completed_at' => now()]);

            BackupLog::write('info', 'Respaldo completado.', $context + [
                'size_bytes' => $this->backup->size_bytes,
                'path'       => $this->backup->path,
            ]);
        } catch (Throwable $e) {
            $this->backup->update([
                'status'       => 'failed',
                'error'        => $e->getMessage(),
                'completed_at' => now(),
            ]);

            BackupLog::write('error', 'Respaldo fallido: ' . $e->getMessage(), $context);

            // Al exception handler: la telemetría lo promueve a caso (fingerprint)
            // y avisa a soporte por correo. No se re-lanza: el estado queda en el registro.
            report($e);
        }
    }
}
