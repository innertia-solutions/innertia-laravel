<?php

namespace Innertia\DataTable\Exceptions;

use InvalidArgumentException;

class UnsupportedExportFormatException extends InvalidArgumentException
{
    public function __construct(string $format)
    {
        parent::__construct(
            "Unsupported export format \"{$format}\". Supported: pdf, xlsx, csv, json."
        );
    }
}
