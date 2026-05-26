<?php

namespace Innertia\Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use Innertia\Workflow\Enums\WorkflowEvent;
use Innertia\Workflow\Models\WorkflowInstance;

class WorkflowCancelled extends DomainEvent
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly Authenticatable  $performedBy,
    ) {}

    public function key(): DomainEventKey
    {
        return WorkflowEvent::Cancelled;
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
            'title' => 'Flujo cancelado',
            'body'  => "El flujo '{$this->instance->definition->label}' fue cancelado.",
        ];
    }

    public function payload(): array
    {
        return [
            'instance_id' => $this->instance->id,
            'definition'  => $this->instance->definition->name,
            'finished_at' => $this->instance->finished_at?->toISOString(),
        ];
    }
}
