<?php

namespace Innertia\Files\Drive\Exceptions;

class DriveItemNotFoundException extends DriveException
{
    public static function for(string $id): self
    {
        return new self("El elemento {$id} no existe en Drive o se perdió el acceso.", 'notFound', 404);
    }
}
