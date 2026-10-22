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

        return $res['vectors'] ?? [];
    }
}
