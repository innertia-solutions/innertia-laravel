<?php

namespace Innertia\Files\Drive\Exceptions;

/** Error de Google Drive. `reason` = error.errors[0].reason de la API cuando existe. */
class DriveException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $reason = null, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
