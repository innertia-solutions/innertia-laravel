<?php

namespace Innertia\Exceptions;

class NotFoundException extends InnertiaException
{
    public function __construct(string $message = 'Resource not found.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'not_found', 404, $previous);
    }
}
