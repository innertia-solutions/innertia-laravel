<?php

namespace Innertia\Files\Drive\Exceptions;

class DriveNotConfiguredException extends DriveException
{
    public function __construct()
    {
        parent::__construct('Google Drive no está configurado: carga la cuenta de servicio de Google Workspace.');
    }
}
