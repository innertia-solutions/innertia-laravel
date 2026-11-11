<?php

namespace Innertia\Files\Drive\Exceptions;

class DriveImportTooLargeException extends DriveException
{
    public function __construct(int $max)
    {
        parent::__construct("La selección supera el máximo de {$max} elementos por importación.", 'tooManyItems');
    }
}
