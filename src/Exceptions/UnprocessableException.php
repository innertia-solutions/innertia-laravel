<?php

namespace Innertia\Exceptions;

class UnprocessableException extends InnertiaException
{
    public function __construct(
        string $message = 'Unprocessable.',
        private readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'unprocessable', 422, $previous);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
