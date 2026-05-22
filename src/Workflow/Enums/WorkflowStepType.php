<?php

namespace Innertia\Workflow\Enums;

enum WorkflowStepType: string
{
    case Start         = 'start';
    case InProgress    = 'in_progress';
    case PauseInternal = 'pause_internal';
    case PauseExternal = 'pause_external';
    case Finished      = 'finished';
    case Cancelled     = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Finished, self::Cancelled => true,
            default                         => false,
        };
    }
}
