<?php

namespace Innertia\Ai\Embeddings;

use Illuminate\Support\Facades\Http;

class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl,
        private int $dim = 1536,
    ) {}

    public function embedDocuments(array $texts): array
    {
        return $this->call(array_values($texts));
    }

    public function embedQuery(string $text): array
    {
        return $this->call([$text])[0] ?? [];
    }

    public function dimension(): int
    {
        return $this->dim;
    }

    /** @return array<int, array<int, float>> */
    private function call(array $texts): array
    {
        $res = Http::withToken($this->apiKey)
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/') . '/embeddings', [
                'model' => $this->model,
                'input' => $texts,
            ])
            ->throw()
            ->json();

        return array_map(fn ($d) => $d['embedding'], $res['data'] ?? []);
    }
}
