<?php
// src/Files/Support/FileVersioning.php

namespace Innertia\Files\Support;

use Illuminate\Support\Facades\Schema;
use Innertia\Files\Exceptions\FileVersioningNotMigratedException;

/**
 * El versionamiento es siempre activo, pero depende de una migración. Un producto
 * desplegado con la lib nueva y sin `migrate` (p. ej. sumando, que migra a mano)
 * debe seguir subiendo archivos: el hook de creación se salta y solo el reemplazo
 * de contenido falla con un mensaje claro.
 */
final class FileVersioning
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('file_versions') && Schema::hasColumn('files', 'version');
    }

    public static function ensureReady(): void
    {
        if (! self::ready()) {
            throw new FileVersioningNotMigratedException();
        }
    }

    /** Para tests y para el comando migrate dentro del mismo proceso. */
    public static function flush(): void
    {
        self::$ready = null;
    }
}
