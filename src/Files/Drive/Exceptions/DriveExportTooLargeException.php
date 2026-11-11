<?php

namespace Innertia\Files\Drive\Exceptions;

class DriveExportTooLargeException extends DriveException
{
    public function __construct()
    {
        parent::__construct('Excede el límite de exportación de Google (10 MB).', 'exportSizeLimitExceeded', 403);
    }
}
