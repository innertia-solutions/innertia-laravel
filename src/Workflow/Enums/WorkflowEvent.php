<?php

namespace Innertia\Workflow\Enums;

use Innertia\Platform\Events\DomainEventKey;

enum WorkflowEvent: string implements DomainEventKey
{
    case Started           = 'workflow.started';
    case Transitioned      = 'workflow.transitioned';
    case TransitionBlocked = 'workflow.transition_blocked';
    case Finished          = 'workflow.finished';
    case Cancelled         = 'workflow.cancelled';

    public function key(): string
    {
        return $this->value;
    }
}
