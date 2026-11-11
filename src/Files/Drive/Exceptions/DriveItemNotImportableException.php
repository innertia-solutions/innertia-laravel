<?php

namespace Innertia\Files\Drive\Exceptions;

class DriveItemNotImportableException extends DriveException
{
    public static function noExport(): self
    {
        return new self('Sin formato exportable.', 'noExport');
    }

    public static function shortcut(): self
    {
        return new self('Los accesos directos no se importan.', 'shortcut');
    }
}
