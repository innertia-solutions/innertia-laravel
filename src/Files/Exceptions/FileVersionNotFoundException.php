<?php

namespace Innertia\Files\Exceptions;

class FileVersionNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $fileId, public readonly int $version)
    {
        parent::__construct("La versión {$version} no existe para este archivo.");
    }
}
