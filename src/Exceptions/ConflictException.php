<?php

namespace Innertia\Exceptions;

class ConflictException extends InnertiaException
{
    public function __construct(string $message = 'Conflict.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'conflict', 409, $previous);
    }
}
