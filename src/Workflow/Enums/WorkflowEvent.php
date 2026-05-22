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
     * Returns a granular subscription key for a specific step.
     * Valid on any WorkflowEvent case — any event type can be step-granular.
     *
     * e.g. WorkflowEvent::Transitioned->forStep('findings') => 'workflow.transitioned.findings'
     * e.g. WorkflowEvent::Started->forStep('planning')     => 'workflow.started.planning'
     */
    public function forStep(string $stepKey): string
    {
        if ($stepKey === '') {
            throw new \InvalidArgumentException('Step key must not be empty.');
        }

        return $this->value . '.' . $stepKey;
    }
}
