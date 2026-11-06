<?php

namespace Innertia\Ai;

use Innertia\Ai\Embeddings\EmbeddingProvider;
use Innertia\Ai\Embeddings\FakeEmbeddingProvider;
use Innertia\Ai\Embeddings\MiniMaxEmbeddingProvider;
use Innertia\Ai\Embeddings\OpenAiEmbeddingProvider;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\RagCollection;
use Innertia\Ai\Transcription\MiniMaxTranscriptionProvider;
use Innertia\Ai\Transcription\OpenAiTranscriptionProvider;
use Innertia\Ai\Transcription\TranscriptionProvider;

/**
 * Fachada de alto nivel del módulo de IA. Resuelve proveedor+modelo por uso desde
 * la BD (ai_assignments) y aplica la config del provider openai-compatible de
 * laravel/ai en runtime para ese uso.
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

        // laravel/ai memoiza la instancia del proveedor: en workers de cola u Octane (procesos que viven
        // entre jobs/requests) un cambio de proveedor/key en BD no se vería. Se purga tras reconfigurar.
        $this->purgeLaravelAiInstance('openai-compatible');

        return $resolved['model'];
    }

    private function purgeLaravelAiInstance(string $provider): void
    {
        if (! class_exists(\Laravel\Ai\AiManager::class)) {
            return;
        }

        try {
            app(\Laravel\Ai\AiManager::class)->purge($provider);
        } catch (\Throwable) {
            // Sin instancia memoizada: nada que purgar.
        }
    }

    public function usage(): UsageLogger
    {
        return new UsageLogger;
    }

    /**
     * Adaptador de transcripción (audio → texto).
     *  - Sin `$providerKey`: el de la asignación de uso `transcription` (proveedor + modelo).
     *  - Con `$providerKey` (`minimax`, `openai`…): ese proveedor; modelo = el de la asignación si apunta a él, si no
     *    su primer modelo con capacidad `transcription`, si no el preset (`asr-1.0` / `whisper-1`).
     * Devuelve null si el proveedor no existe, está inactivo o no tiene API key.
     * El adaptador se elige por el proveedor: MiniMax (key `minimax` o base_url de minimax) usa su API propia
     * (`/speech_to_text`); el resto, la de OpenAI (`/audio/transcriptions`).
     */
    public function transcriber(?string $providerKey = null): ?TranscriptionProvider
    {
        $assignment = AiAssignment::query()->where('use', 'transcription')->where('active', true)->first();

        if ($providerKey === null) {
            $resolved = (new ProviderResolver)->for('transcription');
            $provider = $resolved['provider'] ?? null;
            $model = $resolved['model'] ?? null;
        } else {
            $provider = AiProvider::query()->where('key', $providerKey)->where('active', true)->first();
            $model = $provider && $assignment && (string) $assignment->provider_id === (string) $provider->id
                ? $assignment->model
                : null;
            $model ??= $provider?->models()->get()
                ->first(fn ($m) => in_array('transcription', (array) $m->capabilities, true) && ($m->active ?? true))?->key;
        }

        if (! $provider || ! $provider->active || blank($provider->api_key)) {
            return null;
        }

        $isMiniMax = $provider->key === 'minimax' || str_contains((string) $provider->base_url, 'minimax');
        $baseUrl = (string) ($provider->base_url ?: ($isMiniMax ? 'https://api.minimax.io/v1' : 'https://api.openai.com/v1'));
        $timeout = (int) config('innertia.ai.transcription_timeout', 180);

        return $isMiniMax
            ? new MiniMaxTranscriptionProvider((string) $provider->api_key, $model ?: ProviderPresets::MINIMAX_TRANSCRIPTION, $baseUrl, (string) $provider->key, $timeout)
            : new OpenAiTranscriptionProvider((string) $provider->api_key, $model ?: ProviderPresets::OPENAI_TRANSCRIPTION, $baseUrl, (string) $provider->key, $timeout);
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
            ?: ($embeddingUse['model'] ?? \Innertia\Ai\ProviderPresets::OPENAI_EMBEDDING);

        $dim = (int) ($collection?->dimension ?: 1536);

        $type = $provider?->type ?? 'fake';

        return match ($type) {
            'fake'   => new FakeEmbeddingProvider($dim),
            'openai' => new OpenAiEmbeddingProvider((string) $provider->api_key, $model, (string) $provider->base_url, $dim),
            default  => new MiniMaxEmbeddingProvider((string) $provider->api_key, $model, (string) $provider->base_url, $dim),
        };
    }
}
