<?php

namespace Innertia\Ai;

/**
 * Fachada de alto nivel del módulo de IA. Resuelve proveedor+modelo por uso desde
 * la BD (ai_assignments) y aplica la config del provider openai-compatible de
 * laravel/ai en runtime para ese uso.
 *
 * NOTA: embedder() (Task 3) y usage() (Task 4) se agregan cuando existan sus clases.
 */
class AiManager
{
    public function enabled(): bool
    {
        $resolved = (new ProviderResolver)->for('chat');

        return $resolved !== null && filled($resolved['provider']->api_key);
    }

    /**
     * Resuelve la asignación del uso, carga la config de laravel/ai
     * (providers.openai-compatible.url/key) y devuelve el modelo a usar.
     * Devuelve null si no hay asignación para ese uso.
     */
    public function configureFor(string $use): ?string
    {
        $resolved = (new ProviderResolver)->for($use);
        if (! $resolved) {
            return null;
        }

        config([
            'ai.providers.openai-compatible.url' => (string) $resolved['provider']->base_url,
            'ai.providers.openai-compatible.key' => (string) $resolved['provider']->api_key,
        ]);

        return $resolved['model'];
    }
}
