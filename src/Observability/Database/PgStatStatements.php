<?php

namespace Innertia\Observability\Database;

use Illuminate\Support\Facades\DB;

/**
 * Detecta si pg_stat_statements está USABLE: instalada como extensión Y
 * precargada vía shared_preload_libraries (sin lo segundo, la vista no recolecta).
 */
class PgStatStatements
{
    public static function available(): bool
    {
        return self::reason() === null;
    }

    /** null = disponible; string = por qué no. */
    public static function reason(): ?string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 'Solo disponible en PostgreSQL.';
        }
        try {
            $ext = DB::selectOne(
                "SELECT installed_version FROM pg_available_extensions WHERE name = 'pg_stat_statements'"
            );
            if (! $ext) {
                return 'La extensión pg_stat_statements no está disponible en este servidor.';
            }
            if ($ext->installed_version === null) {
                return 'Ejecuta CREATE EXTENSION pg_stat_statements en la base de datos.';
            }
            $preload = DB::selectOne("SELECT setting FROM pg_settings WHERE name = 'shared_preload_libraries'");
            if (! $preload || ! str_contains($preload->setting ?? '', 'pg_stat_statements')) {
                return 'Agrega pg_stat_statements a shared_preload_libraries y reinicia PostgreSQL.';
            }
            return null;
        } catch (\Throwable $e) {
            return 'No se pudo verificar pg_stat_statements: ' . $e->getMessage();
        }
    }
}
