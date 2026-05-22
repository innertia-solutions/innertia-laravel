<?php

namespace Innertia\Workflow\Restrictions;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class ApprovalRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        $approvals = $instance->context['approvals'] ?? [];
        return isset($approvals[$config['role']]);
    }

    public function message(array $config): string
    {
        return $config['message'] ?? "Se requiere aprobación del rol '{$config['role']}' para continuar.";
    }
}
