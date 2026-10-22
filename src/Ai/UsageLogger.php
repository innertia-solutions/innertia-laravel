<?php

namespace Innertia\Ai;

use Innertia\Ai\Models\UsageEvent;

/**
 * Registra consumo de IA en ai_usage_events (tokens por uso/proveedor/modelo).
 * Base para estadísticas de uso por proveedor activo.
 */
class UsageLogger
{
    public function record(
        string $use,
        ?string $providerId = null,
        ?string $model = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
        ?string $userId = null,
    ): UsageEvent {
        return UsageEvent::create([
            'provider_id'       => $providerId,
            'model'             => $model,
            'use'               => $use,
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $promptTokens + $completionTokens,
            'user_id'           => $userId,
            'created_at'        => now(),
        ]);
    }

    /** Total de tokens registrados para un uso (o todos si null). */
    public function totalTokens(?string $use = null): int
    {
        return (int) UsageEvent::query()
            ->when($use !== null, fn ($q) => $q->where('use', $use))
            ->sum('total_tokens');
    }
}
