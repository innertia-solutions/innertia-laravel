<?php

namespace Innertia\Backups;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Innertia\Backups\Console\PruneBackupsCommand;
use Innertia\Backups\Console\RunBackupCommand;
use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Contracts\ProcessRunner;
use Innertia\Backups\Support\BackupSettings;
use Innertia\Backups\Support\SymfonyProcessRunner;

class BackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->singleton(BackupDriver::class, fn ($app) => $app->make(BackupManager::class)->driver());
    }

    public function boot(): void
    {
        $this->ensureDefaultDisk();

        if ($this->app->runningInConsole()) {
            $this->commands([RunBackupCommand::class, PruneBackupsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule));
    }

    /**
     * Respaldo automático por defecto en todo producto: una entrada por horario
     * (03:00, 11:00, 19:00 hora de Chile). Desactivable con
     * innertia.backups.schedule.enabled = false (BACKUP_SCHEDULE_ENABLED=false).
     */
    public function schedule(Schedule $schedule): void
    {
        if (! BackupSettings::scheduleEnabled()) {
            return;
        }

        foreach (BackupSettings::times() as $time) {
            $schedule->command(RunBackupCommand::class)
                ->dailyAt($time)
                ->timezone(BackupSettings::timezone())
                ->name("innertia:backups:run@{$time}")
                ->withoutOverlapping(360)
                ->onOneServer()
                ->runInBackground();
        }
    }

    /**
     * Si el producto no declaró el disk de respaldos, se registra uno local
     * (storage/app/backups) para que el respaldo funcione sin configuración.
     */
    private function ensureDefaultDisk(): void
    {
        $disk = BackupSettings::disk();

        if (config("filesystems.disks.{$disk}") === null) {
            config(["filesystems.disks.{$disk}" => [
                'driver'     => 'local',
                'root'       => storage_path('app/backups'),
                'visibility' => 'private',
                'throw'      => false,
            ]]);
        }
    }
}
