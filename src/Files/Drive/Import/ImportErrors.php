<?php

namespace Innertia\Files\Drive\Import;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\InvalidNameException;
use Innertia\Files\Directories\Exceptions\MaxDepthExceededException;
use Innertia\Files\Directories\Exceptions\ParentTrashedException;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\FolderNameExhaustedException;
use Innertia\Files\Exceptions\FileVersioningNotMigratedException;

/**
 * Mensaje de error que se guarda en file_imports (y ve el usuario). Solo pasan los mensajes
 * pensados para el usuario (Drive y excepciones de dominio conocidas); cualquier otro error
 * (SQL, rutas, disks…) se reemplaza por un texto genérico. El detalle completo va al log
 * operacional con el trace_id.
 */
final class ImportErrors
{
    public const GENERIC = 'Error interno al importar el elemento (ver registro con el trace_id).';

    /** @var list<class-string<\Throwable>> */
    private const PUBLIC = [
        DriveException::class,
        FolderNameExhaustedException::class,
        MaxDepthExceededException::class,
        ParentTrashedException::class,
        DuplicateDirectoryNameException::class,
        InvalidNameException::class,
        FileVersioningNotMigratedException::class,
    ];

    public static function publicMessage(\Throwable $e): string
    {
        if ($e instanceof LockTimeoutException) {
            return 'Otro proceso está importando este elemento.';
        }

        foreach (self::PUBLIC as $class) {
            if ($e instanceof $class) {
                return $e->getMessage();
            }
        }

        return self::GENERIC;
    }
}
