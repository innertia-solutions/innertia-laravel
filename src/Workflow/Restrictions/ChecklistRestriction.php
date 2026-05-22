<?php

namespace Innertia\Workflow\Restrictions;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class ChecklistRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        $checklist = $instance->context['checklists'][$config['checklist']] ?? [];

        if (empty($checklist)) {
            return false;
        }

        return collect($checklist)->every(fn($item) => ($item['completed'] ?? false) === true);
    }

    public function message(array $config): string
    {
        return $config['message'] ?? "Todos los ítems del checklist '{$config['checklist']}' deben estar completados.";
    }
}
