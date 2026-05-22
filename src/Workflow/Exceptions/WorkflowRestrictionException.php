<?php

namespace Innertia\Workflow\Exceptions;

use RuntimeException;

class WorkflowRestrictionException extends RuntimeException
{
    public function __construct(
        public readonly string $restrictionType,
        string $message,
    ) {
        parent::__construct($message);
    }
}
