<?php

namespace Innertia\Auth\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Genera/actualiza JWT_SECRET en el .env.
 *
 * Reemplaza al comando homónimo de tymon/jwt-auth (ya no se usa esa librería).
 * El codec propio (firebase/php-jwt) firma con esta clave (HS256 por defecto).
 *
 *   php artisan jwt:secret
 *   php artisan jwt:secret --force   # sobreescribe sin preguntar
 */
class JwtSecretCommand extends Command
{
    protected $signature = 'jwt:secret
        {--f|force : Sobreescribir el JWT_SECRET existente sin confirmar}
        {--show    : Solo mostrar la clave generada, sin escribir el .env}';

    protected $description = 'Genera la clave de firma JWT (JWT_SECRET) en el .env';

    public function handle(): int
    {
        $key = Str::random(64);

        if ($this->option('show')) {
            $this->line('<comment>' . $key . '</comment>');
            return self::SUCCESS;
        }

        $path = base_path('.env');

        if (! file_exists($path)) {
            $this->error('.env no encontrado.');
            return self::FAILURE;
        }

        $contents = file_get_contents($path);
        $current  = config('jwt.secret');

        if (! empty($current) && ! $this->option('force')) {
            if (! $this->confirm('Ya existe un JWT_SECRET. ¿Sobreescribir? Invalidará todos los tokens emitidos.')) {
                $this->comment('Operación cancelada.');
                return self::SUCCESS;
            }
        }

        if (str_contains($contents, 'JWT_SECRET=')) {
            $contents = preg_replace('/^JWT_SECRET=.*$/m', 'JWT_SECRET=' . $key, $contents);
        } else {
            $contents .= PHP_EOL . 'JWT_SECRET=' . $key . PHP_EOL;
        }

        file_put_contents($path, $contents);

        $this->info('jwt-auth secret [' . $key . '] set successfully.');

        return self::SUCCESS;
    }
}
