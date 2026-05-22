<?php

namespace Innertia\Workflow\Traits;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Innertia\Workflow\Models\WorkflowInstance;

/**
 * Añade a cualquier modelo Eloquent acceso a su WorkflowInstance activa.
 *
 * Uso:
 *   class Project extends Model
 *   {
 *       use HasWorkflow;
 *   }
 *
 *   $project->workflowInstance           // WorkflowInstance|null
 *   $project->workflowInstance->context  // array con datos del dominio
 */
trait HasWorkflow
{
    public function workflowInstance(): MorphOne
    {
        return $this->morphOne(WorkflowInstance::class, 'workflowable');
    }
}
