<?php

namespace Innertia\Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Workflow\Enums\WorkflowEvent;
use Innertia\Workflow\Models\WorkflowInstance;

class WorkflowTransitioned extends DomainEvent
{
    const KEY = 'workflow.transitioned';

    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly string           $fromStep,
        public readonly string           $fromLabel,
        public readonly string           $toStep,
        public readonly string           $toLabel,
        public readonly Authenticatable  $performedBy,
    ) {}

    /**
     * Clave específica al step: permite suscripciones granulares.
     * Ej: 'workflow.transitioned.findings'
     */
    public function resolvedKey(): string
    {
        return WorkflowEvent::Transitioned->forStep($this->toStep);
    }

    public function channels(): array
    {
        return ['web', 'mail'];
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
            'title' => "Avanzó a: {$this->toLabel}",
            'body'  => "De '{$this->fromLabel}' → '{$this->toLabel}'.",
        ];
    }

    public function payload(): array
    {
        return [
            'instance_id' => $this->instance->id,
            'from_step'   => $this->fromStep,
            'from_label'  => $this->fromLabel,
            'to_step'     => $this->toStep,
            'to_label'    => $this->toLabel,
        ];
    }
}
