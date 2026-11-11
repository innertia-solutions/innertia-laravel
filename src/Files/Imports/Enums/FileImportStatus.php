<?php

namespace Innertia\Files\Imports\Enums;

enum FileImportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done    = 'done';
    case Failed  = 'failed';
}
