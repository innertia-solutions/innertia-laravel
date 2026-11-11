<?php

namespace Innertia\Passkeys;

use Illuminate\Support\ServiceProvider;
use Innertia\Passkeys\Console\RevokePasskeysCommand;

class PasskeysServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/passkeys');

        if ($this->app->runningInConsole() && class_exists(RevokePasskeysCommand::class)) {
            $this->commands([RevokePasskeysCommand::class]);
        }
    }
}
