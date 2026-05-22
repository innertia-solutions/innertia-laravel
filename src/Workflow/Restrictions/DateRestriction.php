<?php

namespace Innertia\Workflow\Restrictions;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class DateRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        $after = Carbon::parse($config['after']);
        return now()->greaterThanOrEqualTo($after);
    }

    public function message(array $config): string
    {
        return $config['message'] ?? "Esta transición no está disponible hasta el {$config['after']}.";
    }
}
