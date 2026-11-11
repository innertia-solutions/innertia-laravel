<?php

namespace Innertia\Files\Exceptions;

class FileTrashedException extends \RuntimeException
{
    public function __construct(public readonly string $fileId)
    {
        parent::__construct('El archivo está en la papelera; restáuralo antes de cambiar su contenido.');
    }
}
