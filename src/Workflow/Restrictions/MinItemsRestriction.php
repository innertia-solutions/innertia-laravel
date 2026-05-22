<?php

namespace Innertia\Workflow\Restrictions;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class MinItemsRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        $workflowable = $instance->workflowable;
        $relation     = $config['relation'];
        $min          = $config['min'];

        if (! method_exists($workflowable, $relation)) {
            return false;
        }

        return $workflowable->{$relation}()->count() >= $min;
    }

    public function message(array $config): string
    {
        return $config['message'] ?? "Se requieren al menos {$config['min']} elemento(s) en '{$config['relation']}'.";
    }
}
