<?php

namespace Innertia\Exceptions;

class ForbiddenException extends InnertiaException
{
    public function __construct(string $message = 'Forbidden.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'forbidden', 403, $previous);
    }
}
