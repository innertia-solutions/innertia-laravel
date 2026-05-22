<?php

namespace Innertia\Workflow\Restrictions;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class DocumentRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        $docs = $instance->context['documents'] ?? [];
        return isset($docs[$config['document']]) && ! empty($docs[$config['document']]);
    }

    public function message(array $config): string
    {
        return $config['message'] ?? "El documento '{$config['document']}' es obligatorio para continuar.";
    }
}
