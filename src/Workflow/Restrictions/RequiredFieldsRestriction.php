<?php

namespace Innertia\Workflow\Restrictions;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class RequiredFieldsRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        foreach ($config['fields'] as $field) {
            $value = data_get($instance->context, $field);
            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }

    public function message(array $config): string
    {
        $fields = implode(', ', $config['fields']);
        return $config['message'] ?? "Los siguientes campos son obligatorios: {$fields}.";
    }
}
