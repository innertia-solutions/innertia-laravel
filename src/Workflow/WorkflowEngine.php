<?php

namespace Innertia\Workflow;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Enums\WorkflowStepType;
use Innertia\Workflow\Enums\WorkflowStatus;
use Innertia\Workflow\Exceptions\WorkflowRestrictionException;
use Innertia\Workflow\Models\WorkflowDefinition;
use Innertia\Workflow\Models\WorkflowInstance;
use Innertia\Workflow\Models\WorkflowTransitionLog;
use Innertia\Workflow\Restrictions\ApprovalRestriction;
use Innertia\Workflow\Restrictions\ChecklistRestriction;
use Innertia\Workflow\Restrictions\CustomRestriction;
use Innertia\Workflow\Restrictions\DateRestriction;
use Innertia\Workflow\Restrictions\DocumentRestriction;
use Innertia\Workflow\Restrictions\MinItemsRestriction;
use Innertia\Workflow\Restrictions\RequiredFieldsRestriction;
use Innertia\Workflow\Restrictions\RoleRestriction;

class WorkflowEngine
{
    // ── Arrancar un flujo ─────────────────────────────────────────────────────

    public function start(
        Model $workflowable,
        WorkflowDefinition $definition,
        array $context = [],
        ?Authenticatable $startedBy = null,
    ): WorkflowInstance {
        // Validar que la entidad sea del tipo correcto
        if (! ($workflowable instanceof ($definition->entity_type))) {
            throw new \InvalidArgumentException(
                "Esta definición es para [{$definition->entity_type}], se recibió [" . get_class($workflowable) . "]."
            );
        }

        $startStep = collect($definition->config['steps'])
            ->firstWhere('type', WorkflowStepType::Start->value);

        if (! $startStep) {
            throw new \InvalidArgumentException("La definición [{$definition->name}] no tiene step de tipo 'start'.");
        }

        $instance = WorkflowInstance::create([
            'tenant_id'         => $definition->tenant_id ?? ($workflowable->tenant_id ?? null),
            'definition_id'     => $definition->id,
            'workflowable_type' => get_class($workflowable),
            'workflowable_id'   => $workflowable->getKey(),
            'current_step'      => $startStep['key'],
            'context'           => $context,
            'status'            => WorkflowStatus::Active->value,
            'started_at'        => now(),
        ]);

        // TODO: WorkflowStarted::dispatch(instance: $instance, startedBy: $startedBy);

        return $instance;
    }

    // ── Ejecutar una transición ───────────────────────────────────────────────

    public function transition(
        WorkflowInstance $instance,
        string $toStep,
        Authenticatable $performedBy,
        ?string $notes = null,
    ): WorkflowInstance {
        if (! $instance->isActive()) {
            throw new \RuntimeException("El flujo ya está cerrado (status: {$instance->status->value}).");
        }

        $definition = $instance->definition;
        $transition = $definition->findTransition($instance->current_step, $toStep);

        if (! $transition) {
            throw new WorkflowRestrictionException(
                'invalid_transition',
                "Transición de '{$instance->current_step}' a '{$toStep}' no está definida en este flujo."
            );
        }

        // Evaluar restricciones — si falla, despacha evento de bloqueo y relanza
        try {
            $this->checkRestrictions($transition['restrictions'] ?? [], $instance, $performedBy);
        } catch (WorkflowRestrictionException $e) {
            // TODO: WorkflowTransitionBlocked::dispatch(...)
            throw $e;
        }

        $fromStep       = $instance->current_step;
        $fromStepConfig = $definition->findStep($fromStep);
        $toStepConfig   = $definition->findStep($toStep);

        // Ejecutar transición
        $instance->update(['current_step' => $toStep]);

        // Registrar en el log
        WorkflowTransitionLog::create([
            'instance_id'  => $instance->id,
            'from_step'    => $fromStep,
            'to_step'      => $toStep,
            'performed_by' => (string) $performedBy->getAuthIdentifier(),
            'notes'        => $notes,
            'performed_at' => now(),
        ]);

        // TODO: WorkflowTransitioned::dispatch(instance: $instance, fromStep: $fromStep, toStep: $toStep, ...)

        // Si llegamos a un step terminal, cerrar la instancia
        $stepType = WorkflowStepType::from($toStepConfig['type']);

        if ($stepType === WorkflowStepType::Finished) {
            $instance->update([
                'status'      => WorkflowStatus::Finished->value,
                'finished_at' => now(),
            ]);
            // TODO: WorkflowFinished::dispatch(instance: $instance, performedBy: $performedBy);
        } elseif ($stepType === WorkflowStepType::Cancelled) {
            $instance->update([
                'status'      => WorkflowStatus::Cancelled->value,
                'finished_at' => now(),
            ]);
            // TODO: WorkflowCancelled::dispatch(instance: $instance, performedBy: $performedBy);
        }

        return $instance->fresh();
    }

    // ── Consultas ─────────────────────────────────────────────────────────────

    public function canTransition(WorkflowInstance $instance, string $toStep, Authenticatable $user): bool
    {
        try {
            if (! $instance->isActive()) {
                return false;
            }

            $transition = $instance->definition->findTransition($instance->current_step, $toStep);
            if (! $transition) {
                return false;
            }

            $this->checkRestrictions($transition['restrictions'] ?? [], $instance, $user);
            return true;
        } catch (WorkflowRestrictionException) {
            return false;
        }
    }

    /**
     * Retorna los steps a los que el usuario puede ir ahora desde el step actual.
     */
    public function availableTransitions(WorkflowInstance $instance, Authenticatable $user): array
    {
        if (! $instance->isActive()) {
            return [];
        }

        $definition  = $instance->definition;
        $transitions = $definition->transitionsFrom($instance->current_step);

        return collect($transitions)
            ->filter(fn($t) => $this->canTransition($instance, $t['to'], $user))
            ->map(fn($t) => $definition->findStep($t['to']))
            ->filter()
            ->values()
            ->toArray();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function checkRestrictions(array $restrictions, WorkflowInstance $instance, Authenticatable $user): void
    {
        foreach ($restrictions as $restrictionConfig) {
            $evaluator = $this->resolveEvaluator($restrictionConfig['type']);

            if (! $evaluator->evaluate($restrictionConfig, $instance, $user)) {
                throw new WorkflowRestrictionException(
                    $restrictionConfig['type'],
                    $evaluator->message($restrictionConfig),
                );
            }
        }
    }

    private function resolveEvaluator(string $type): RestrictionEvaluator
    {
        return match($type) {
            'checklist'       => new ChecklistRestriction(),
            'required_fields' => new RequiredFieldsRestriction(),
            'role'            => new RoleRestriction(),
            'approval'        => new ApprovalRestriction(),
            'min_items'       => new MinItemsRestriction(),
            'document'        => new DocumentRestriction(),
            'date'            => new DateRestriction(),
            'custom'          => new CustomRestriction(),
            default           => throw new \InvalidArgumentException("Tipo de restricción desconocido: [{$type}]."),
        };
    }
}
