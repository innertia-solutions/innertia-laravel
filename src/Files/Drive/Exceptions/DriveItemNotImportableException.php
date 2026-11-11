<?php

namespace Innertia\Files\Drive\Exceptions;

class DriveItemNotImportableException extends DriveException
{
    public static function noExport(): self
    {
        return new self('Sin formato exportable.', 'noExport');
    }

    /** 403 de Google: el propietario (o Google, por abuso) impide bajar/exportar el archivo. */
    public static function downloadDisabled(?string $reason = null): self
    {
        return new self('El propietario deshabilitó la descarga de este archivo.', $reason ?? 'downloadDisabled', 403);
    }

    public static function shortcut(): self
    {
        return new self('Los accesos directos no se importan.', 'shortcut');
    }
}
