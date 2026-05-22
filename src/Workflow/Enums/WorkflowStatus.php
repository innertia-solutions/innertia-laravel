<?php

namespace Innertia\Workflow\Enums;

enum WorkflowStatus: string
{
    case Active    = 'active';
    case Finished  = 'finished';
    case Cancelled = 'cancelled';
}
