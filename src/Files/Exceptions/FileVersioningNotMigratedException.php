<?php

namespace Innertia\Files\Exceptions;

class FileVersioningNotMigratedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('El versionamiento de archivos no está migrado. Corre `php artisan migrate`.');
    }
}
