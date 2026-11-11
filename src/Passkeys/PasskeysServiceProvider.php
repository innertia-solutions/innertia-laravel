<?php

namespace Innertia\Passkeys;

use Illuminate\Support\ServiceProvider;
use Innertia\Passkeys\Console\RevokePasskeysCommand;

class PasskeysServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Opt-in (mismo patrón que Payments): sin habilitar no impone la tabla.
        if (! config('innertia.passkeys.enabled', false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/passkeys');

        if ($this->app->runningInConsole() && class_exists(RevokePasskeysCommand::class)) {
            $this->commands([RevokePasskeysCommand::class]);
        }
    }
}
