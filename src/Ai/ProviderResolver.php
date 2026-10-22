<?php

namespace Innertia\Ai;

use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiProvider;

/**
 * Resuelve, para un uso dado (chat|vision|embedding|assistant), qué proveedor y
 * modelo usar — leyendo la tabla ai_assignments. Permite distinto proveedor por uso.
 */
class ProviderResolver
{
    /** @return array{provider: AiProvider, model: string}|null */
    public function for(string $use): ?array
    {
        $assignment = AiAssignment::query()->where('use', $use)->where('active', true)->first();
        if (! $assignment) {
            return null;
        }

        $provider = $assignment->provider()->first() ?? AiProvider::find($assignment->provider_id);
        if (! $provider) {
            return null;
        }

        return ['provider' => $provider, 'model' => (string) $assignment->model];
    }
}
