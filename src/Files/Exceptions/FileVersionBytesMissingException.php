<?php

namespace Innertia\Files\Exceptions;

/** Los bytes de una versión superada ya no están en su disk (no se puede restaurar). */
class FileVersionBytesMissingException extends \RuntimeException
{
    public function __construct(public readonly int $version)
    {
        parent::__construct("Los bytes de la versión {$version} no existen en storage.");
    }
}
