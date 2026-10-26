<?php

namespace Innertia\Ai\Rag;

use Illuminate\Support\Facades\DB;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\RagCollection;

/**
 * Recupera los k chunks más cercanos a una consulta usando la distancia pgvector
 * (`<->`). Soporta filtros de igualdad sobre claves de metadata (JSON).
 * Requiere pgsql (pgvector); en otros drivers lanza RuntimeException.
 */
class RagRetriever
{
    /** @return array<int, array{content: string, metadata: array, distance: float}> */
    public function search(string $collectionKey, string $query, array $metadataFilters = [], int $k = 5): array
    {
        $collection = RagCollection::query()->where('key', $collectionKey)->firstOrFail();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('RagRetriever::search requiere pgsql (pgvector <->).');
        }

        $vector = Ai::embedder($collectionKey)->embedQuery($query);
        if ($vector === []) {
            // Sin embedding de la query no hay búsqueda posible; evitar `[]::vector`
            // (pgvector exige ≥1 dimensión). El caller decide cómo degradar.
            throw new \RuntimeException('No se pudo generar el embedding de la consulta.');
        }

        $literal = '[' . implode(',', array_map(fn ($v) => (float) $v, $vector)) . ']';

        $sql      = 'SELECT content, metadata, (embedding <-> ?::vector) AS distance FROM ai_doc_chunks WHERE collection_id = ?';
        $bindings = [$literal, $collection->id];

        foreach ($metadataFilters as $key => $value) {
            $sql .= ' AND metadata->>? = ?';
            $bindings[] = (string) $key;
            $bindings[] = (string) $value;
        }

        $sql .= ' ORDER BY distance ASC LIMIT ' . (int) $k;

        return array_map(fn ($r) => [
            'content'  => $r->content,
            'metadata' => json_decode($r->metadata ?? '{}', true) ?: [],
            'distance' => (float) $r->distance,
        ], DB::select($sql, $bindings));
    }
}
