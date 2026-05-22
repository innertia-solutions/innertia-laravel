<?php

namespace Innertia\Workflow\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Models\WorkflowInstance;

interface RestrictionEvaluator
{
    /**
     * @param  array            $config    Config de esta restricción del YAML
     * @param  WorkflowInstance $instance  Instancia activa del flujo
     * @param  Authenticatable  $user      Usuario que intenta la transición
     */
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool;

    /**
     * Mensaje legible cuando la restricción falla.
     */
    public function message(array $config): string;
}
