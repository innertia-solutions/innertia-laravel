<?php

namespace Innertia\Exceptions;

class PlatformAccountException extends InnertiaException
{
    public function __construct(
        string $message = 'Esta es una cuenta de plataforma. Usa el acceso de plataforma.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'platform_account', 409, $previous);
    }
}
