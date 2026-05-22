<?php

namespace Innertia\Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Workflow\Models\WorkflowInstance;

class WorkflowStarted extends DomainEvent
{
    const KEY = 'workflow.started';

    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly ?Authenticatable $startedBy,
    ) {}

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
            'title' => 'Flujo iniciado',
            'body'  => "El flujo '{$this->instance->definition->label}' fue iniciado.",
        ];
    }

    public function payload(): array
    {
        return [
            'instance_id'  => $this->instance->id,
            'definition'   => $this->instance->definition->name,
            'current_step' => $this->instance->current_step,
        ];
    }
}
