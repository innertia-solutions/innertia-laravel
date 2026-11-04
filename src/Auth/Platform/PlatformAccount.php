<?php

namespace Innertia\Auth\Platform;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cuenta owner de la consola /platform (identidad de plataforma separada).
 *
 * Genérico para todos los productos:
 *  - ensureDefault(): crea `platform@innertia.cl` (config innertia.platform.default_email)
 *    si no existe, con clave temporal y cambio obligatorio. Corre solo tras `migrate`.
 *  - reset(): clave temporal nueva + cambio obligatorio (`php artisan platform:password`).
 *
 * La clave temporal nunca es definitiva: al entrar, la consola exige definir una
 * nueva (force_password_change) y bloquea el resto de la API hasta hacerlo.
 */
class PlatformAccount
{
    public static function defaultEmail(): string
    {
        return strtolower((string) config('innertia.platform.default_email', 'platform@innertia.cl'));
    }

    /** Las tablas del producto soportan cuentas de plataforma. */
    public static function supported(): bool
    {
        $table = (new (static::model()))->getTable();

        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'is_platform_admin')
            && Schema::hasColumn($table, 'force_password_change');
    }

    /**
     * Crea la cuenta por defecto si no existe. Nunca pisa una existente.
     * Clave: innertia.platform.default_password; si no está definida, en local
     * se usa 'Innertia1234!' y fuera de local una aleatoria (no usable): ahí se
     * entra generando una temporal con `php artisan platform:password`.
     *
     * @return string|null clave temporal asignada, o null si la cuenta ya existía
     */
    public static function ensureDefault(): ?string
    {
        $model = static::model();
        $email = static::defaultEmail();

        if ($model::where('email', $email)->exists()) {
            return null;
        }

        $password = config('innertia.platform.default_password')
            ?: (app()->environment('local') ? 'Innertia1234!' : Str::password(32));

        static::write((new $model)->forceFill(['email' => $email, 'name' => 'Innertia Platform']), $password);

        return $password;
    }

    /**
     * Fija una clave temporal (generada si no se pasa) y exige cambiarla al entrar.
     * Crea la cuenta como owner si no existe. Rechaza cuentas que no son de plataforma.
     *
     * @return string la clave temporal
     */
    public static function reset(?string $email = null, ?string $password = null): string
    {
        $model = static::model();
        $email = strtolower(trim($email ?: static::defaultEmail()));
        $user  = $model::where('email', $email)->first();

        if ($user && ! $user->is_platform_admin) {
            throw new \InvalidArgumentException("{$email} existe pero no es una cuenta de plataforma.");
        }

        $password = $password ?: Str::password(16, symbols: false);

        static::write($user ?? (new $model)->forceFill(['email' => $email, 'name' => 'Innertia Platform']), $password);

        return $password;
    }

    private static function write($user, string $password): void
    {
        $attrs = [
            'password'              => Hash::make($password),
            'email_verified_at'     => $user->email_verified_at ?? now(),
            'is_platform_admin'     => true,
            'force_password_change' => true,
        ];

        if (Schema::hasColumn($user->getTable(), 'platform_role')) {
            $attrs['platform_role'] = $user->platform_role ?? 'owner';
        }

        $user->forceFill($attrs)->save();
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    private static function model(): string
    {
        return config('auth.providers.users.model');
    }
}
