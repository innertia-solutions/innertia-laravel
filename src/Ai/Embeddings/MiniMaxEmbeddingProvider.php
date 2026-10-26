<?php

namespace Innertia\Ai\Embeddings;

use Illuminate\Support\Facades\Http;

class MiniMaxEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl,
        private int $dim = 1536,
    ) {}

    public function embedDocuments(array $texts): array
    {
        return $this->call(array_values($texts), 'db');
    }

    public function embedQuery(string $text): array
    {
        return $this->call([$text], 'query')[0] ?? [];
    }

    public function dimension(): int
    {
        return $this->dim;
    }

    /** @return array<int, array<int, float>> */
    private function call(array $texts, string $type): array
    {
        $res = Http::withToken($this->apiKey)
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/') . '/embeddings', [
                'model' => $this->model,
                'texts' => $texts,
                'type'  => $type,
            ])
            ->throw()
            ->json();

        // MiniMax envuelve errores lógicos en `base_resp` con HTTP 200 (rate limit,
        // modelo inválido, etc.), así que ->throw() no los caza. Detectarlos acá para
        // no devolver vectores vacíos que luego rompen pgvector (`[]::vector`).
        $status = $res['base_resp']['status_code'] ?? 0;
        if ($status !== 0) {
            throw new \RuntimeException('MiniMax embeddings error ' . $status . ': ' . ($res['base_resp']['status_msg'] ?? 'desconocido'));
        }

        if (empty($res['vectors'])) {
            throw new \RuntimeException('MiniMax embeddings devolvió una respuesta sin vectores.');
        }

        return $res['vectors'];
    }
}
