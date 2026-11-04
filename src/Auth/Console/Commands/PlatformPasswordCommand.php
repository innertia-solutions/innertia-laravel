<?php

namespace Innertia\Auth\Console\Commands;

use Illuminate\Console\Command;
use Innertia\Auth\Platform\PlatformAccount;

/**
 * Recuperación de la consola /platform sin depender del correo:
 *
 *   php artisan platform:password                     # clave temporal para platform@innertia.cl
 *   php artisan platform:password --password='X...'   # clave temporal concreta
 *   php artisan platform:password --email=otra@x.cl   # otra cuenta de plataforma
 *
 * Crea la cuenta (owner) si no existe. Al entrar con la clave temporal la consola
 * exige definir una nueva.
 */
class PlatformPasswordCommand extends Command
{
    protected $signature = 'platform:password
        {--email= : Cuenta de plataforma (default innertia.platform.default_email)}
        {--password= : Clave temporal a fijar (si se omite se genera una)}';

    protected $description = 'Genera una clave temporal para la cuenta de /platform (pide una nueva al entrar).';

    public function handle(): int
    {
        if (! PlatformAccount::supported()) {
            $this->error('La tabla de usuarios no tiene columnas de plataforma (is_platform_admin / force_password_change).');

            return self::FAILURE;
        }

        try {
            $password = PlatformAccount::reset($this->option('email'), $this->option('password'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage().' No se modifica.');

            return self::FAILURE;
        }

        $this->info('Clave temporal lista. Al entrar a /platform se pedirá una contraseña nueva.');
        $this->line('  Correo:         '.($this->option('email') ?: PlatformAccount::defaultEmail()));
        $this->line('  Clave temporal: '.$password);

        return self::SUCCESS;
    }
}
