<?php

namespace Innertia\Exceptions;

use RuntimeException;

abstract class InnertiaException extends RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly string $errorKey = 'error',
        private readonly int $statusCode = 500,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorKey(): string
    {
        return $this->errorKey;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
