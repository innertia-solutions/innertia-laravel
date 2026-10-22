<?php

namespace Innertia\Ai\Embeddings;

/**
 * Contrato de embeddings: convierte texto en vectores. Cada proveedor (MiniMax,
 * OpenAI, Fake) implementa su propio transporte; el selector por `type` del
 * AiProvider decide cuál se instancia (ver AiManager::embedder()).
 */
interface EmbeddingProvider
{
    /** @param string[] $texts @return array<int, array<int, float>> un vector por texto */
    public function embedDocuments(array $texts): array;

    /** @return array<int, float> */
    public function embedQuery(string $text): array;

    public function dimension(): int;
}
