<?php

namespace Innertia\Files\Drive\Exceptions;

class DriveFileTooLargeException extends DriveException
{
    public function __construct(int $maxBytes)
    {
        $mb = (int) round($maxBytes / 1048576);
        parent::__construct("Excede el tamaño máximo permitido ({$mb} MB).", 'fileTooLarge');
    }
}
