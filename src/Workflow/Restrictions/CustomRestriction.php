<?php

namespace Innertia\Workflow\Restrictions;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Workflow\Contracts\RestrictionEvaluator;
use Innertia\Workflow\Models\WorkflowInstance;

class CustomRestriction implements RestrictionEvaluator
{
    public function evaluate(array $config, WorkflowInstance $instance, Authenticatable $user): bool
    {
        $id = $this->resolveId($config['id_from'], $instance);

        if (! $id) {
            return false;
        }

        $entityClass = $config['entity'];
        $entity      = $entityClass::find($id);

        if (! $entity) {
            return false;
        }

        return $entity->{$config['field']} == $config['value'];
    }

    public function message(array $config): string
    {
        return $config['message'] ?? 'Una condición externa requerida no está cumplida.';
    }

    private function resolveId(string $idFrom, WorkflowInstance $instance): mixed
    {
        // 'context.program_id' → $instance->context['program_id']
        if (str_starts_with($idFrom, 'context.')) {
            $key = substr($idFrom, 8);
            return $instance->context[$key] ?? null;
        }

        // Soporte para otros paths futuros via data_get
        return data_get($instance, $idFrom);
    }
}
