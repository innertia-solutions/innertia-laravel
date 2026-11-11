<?php

namespace Innertia\Files\Drive\Exceptions;

/** No quedó sufijo libre (" (2)", " (3)"…) para recrear una carpeta de Drive. Mensaje apto para el usuario. */
class FolderNameExhaustedException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct("No se pudo crear la carpeta \"{$name}\": demasiados nombres repetidos.");
    }
}
