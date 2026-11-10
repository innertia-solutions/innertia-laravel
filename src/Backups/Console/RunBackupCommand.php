<?php

namespace Innertia\Backups\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\Support\BackupLog;
use Innertia\Backups\Support\BackupSettings;
use Innertia\Backups\UseCases\PruneBackups;
use Innertia\Backups\UseCases\RunBackup;

/**
 * Respaldo de la BD (pg_dump -Fc) + poda por retención. Es lo que corre el
 * schedule por defecto de la lib (3 veces al día). Síncrono a propósito: un
 * dump grande no cabe en el $timeout=60 de un UseCase encolado.
 */
class RunBackupCommand extends Command
{
    protected $signature = 'innertia:backups:run {--no-prune : No aplicar la retención al terminar}';

    protected $description = 'Genera un respaldo de la base de datos (pg_dump) y poda los antiguos según la retención.';

    public function handle(): int
    {
        if (! Schema::hasTable('backups')) {
            BackupLog::write('warning', 'Respaldo omitido: la tabla backups no existe (falta migrate).');
            $this->warn('La tabla backups no existe — corre las migraciones.');

            return self::SUCCESS;
        }

        if (Backup::whereIn('status', ['pending', 'running'])->where('created_at', '>', now()->subHours(6))->exists()) {
            BackupLog::write('warning', 'Respaldo omitido: ya hay uno en curso.');
            $this->warn('Ya hay un respaldo en curso — se omite.');

            return self::SUCCESS;
        }

        $mode = BackupSettings::mode();
        $backup = Backup::create([
            'disk'   => BackupSettings::disk(),
            'driver' => $mode,
            'mode'   => $mode,
            'status' => 'pending',
        ]);

        (new RunBackup($backup))->execute();
        $backup->refresh();

        if ($backup->status !== 'completed') {
            $this->error("Respaldo {$backup->id} falló: {$backup->error}");

            return self::FAILURE;
        }

        $this->info("Respaldo {$backup->id} completado ({$backup->size_bytes} bytes).");

        if (! $this->option('no-prune')) {
            $pruned = (new PruneBackups())->execute();
            BackupLog::write('info', "Poda de respaldos: {$pruned} eliminados.", ['pruned' => $pruned]);
            $this->info("Poda: {$pruned} respaldos eliminados.");
        }

        return self::SUCCESS;
    }
}
