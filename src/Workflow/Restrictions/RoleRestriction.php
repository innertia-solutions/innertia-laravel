<?php

namespace Innertia\Workflow\Restrictions;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class RoleRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        $requiredRoles = $config['roles'];

        // Usa HasRoles::roles si está disponible
        if (method_exists($user, 'roles')) {
            $userRoles = $user->roles->pluck('slug')->toArray();
            return ! empty(array_intersect($userRoles, $requiredRoles));
        }

        return false;
    }

    public function message(array $config): string
    {
        $roles = implode(', ', $config['roles']);
        return $config['message'] ?? "Solo usuarios con los roles [{$roles}] pueden ejecutar esta transición.";
    }
}
