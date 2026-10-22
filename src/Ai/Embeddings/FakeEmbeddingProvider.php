<?php

namespace Innertia\Ai\Embeddings;

/**
 * Embeddings deterministas sin red (dev/tests). Mismo texto → mismo vector,
 * longitud = dimension. No representa semántica real, solo estabilidad.
 */
class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private int $dim = 1536) {}

    public function embedDocuments(array $texts): array
    {
        return array_map(fn ($t) => $this->vector((string) $t), array_values($texts));
    }

    public function embedQuery(string $text): array
    {
        return $this->vector($text);
    }

    public function dimension(): int
    {
        return $this->dim;
    }

    /** @return array<int, float> */
    private function vector(string $text): array
    {
        $seed = crc32($text);
        $out  = [];
        for ($i = 0; $i < $this->dim; $i++) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $out[] = ($seed % 1000) / 1000;
        }

        return $out;
    }
}
