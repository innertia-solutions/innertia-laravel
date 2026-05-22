<?php

namespace Innertia\Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Workflow\Enums\WorkflowEvent;
use Innertia\Workflow\Models\WorkflowInstance;

class WorkflowTransitionBlocked extends DomainEvent
{
    const KEY = 'workflow.transition_blocked';

    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly string           $fromStep,
        public readonly string           $toStep,
        public readonly string           $blockedBy,   // tipo de restricción que falló
        public readonly string           $reason,      // mensaje legible
        public readonly Authenticatable  $attemptedBy,
    ) {}

    public function resolvedKey(): string
    {
        return WorkflowEvent::TransitionBlocked->forStep($this->toStep);
    }

    public function channels(): array
    {
        return ['web'];
    }

    public function subscribable(): ?Model
    {
        return $this->instance;
    }

    public function ancestors(): array
    {
        return array_filter([$this->instance->workflowable]);
    }

    public function toWeb(): ?array
    {
        return [
            'title' => "Transición bloqueada: {$this->toStep}",
            'body'  => $this->reason,
        ];
    }

    public function payload(): array
    {
        return [
            'instance_id' => $this->instance->id,
            'from_step'   => $this->fromStep,
            'to_step'     => $this->toStep,
            'blocked_by'  => $this->blockedBy,
            'reason'      => $this->reason,
        ];
    }
}
