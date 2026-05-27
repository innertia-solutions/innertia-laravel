<?php

namespace Innertia\Files\Exceptions;

class AccessDeniedException extends \RuntimeException
{
    public function __construct(string $message = 'You do not have permission to access this resource.')
    {
        parent::__construct($message);
    }
}
