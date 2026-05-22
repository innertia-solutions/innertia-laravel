<?php

namespace Innertia\Workflow\Enums;

enum WorkflowEvent: string
{
    case Started          = 'workflow.started';
    case Transitioned     = 'workflow.transitioned';
    case TransitionBlocked = 'workflow.transition_blocked';
    case Finished         = 'workflow.finished';
    case Cancelled        = 'workflow.cancelled';

    /**
     * Returns a granular key for a specific step transition.
     * e.g. WorkflowEvent::Transitioned->forStep('findings') => 'workflow.transitioned.findings'
     */
    public function forStep(string $stepKey): string
    {
        return $this->value . '.' . $stepKey;
    }
}
