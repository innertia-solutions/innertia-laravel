<?php

namespace Innertia\Ai;

use Innertia\Ai\Embeddings\EmbeddingProvider;
use Innertia\Ai\Embeddings\FakeEmbeddingProvider;
use Innertia\Ai\Embeddings\MiniMaxEmbeddingProvider;
use Innertia\Ai\Embeddings\OpenAiEmbeddingProvider;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\RagCollection;

/**
 * Fachada de alto nivel del módulo de IA. Resuelve proveedor+modelo por uso desde
 * la BD (ai_assignments) y aplica la config del provider openai-compatible de
 * laravel/ai en runtime para ese uso.
 *
 * NOTA: usage() (Task 4) se agrega cuando existan sus clases.
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

    /**
     * Devuelve el adaptador de embeddings para una colección RAG (por su key) o,
     * si no se da colección, el del uso global `embedding`. El adaptador concreto
     * se elige por el `type` del AiProvider (fake|openai|minimax/openai-compatible).
     */
    public function embedder(?string $collectionKey = null): EmbeddingProvider
    {
        $collection = $collectionKey
            ? RagCollection::query()->where('key', $collectionKey)->first()
            : null;

        $embeddingUse = (new ProviderResolver)->for('embedding');

        $provider = $collection?->embedding_provider_id
            ? AiProvider::find($collection->embedding_provider_id)
            : ($embeddingUse['provider'] ?? null);

        $model = $collection?->embedding_model
            ?: ($embeddingUse['model'] ?? 'embo-01');

        $dim = (int) ($collection?->dimension ?: 1536);

        $type = $provider?->type ?? 'fake';

        return match ($type) {
            'fake'   => new FakeEmbeddingProvider($dim),
            'openai' => new OpenAiEmbeddingProvider((string) $provider->api_key, $model, (string) $provider->base_url, $dim),
            default  => new MiniMaxEmbeddingProvider((string) $provider->api_key, $model, (string) $provider->base_url, $dim),
        };
    }
}
