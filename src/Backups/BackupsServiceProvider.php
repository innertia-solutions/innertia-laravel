<?php

namespace Innertia\Backups;

use Illuminate\Support\ServiceProvider;
use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Contracts\ProcessRunner;
use Innertia\Backups\Support\SymfonyProcessRunner;

class BackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->singleton(BackupDriver::class, fn ($app) => $app->make(BackupManager::class)->driver());
    }
}
