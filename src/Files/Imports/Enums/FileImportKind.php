<?php

namespace Innertia\Files\Imports\Enums;

enum FileImportKind: string
{
    case Import  = 'import';
    case Refresh = 'refresh';
}
