<?php

namespace Innertia\Ai\Rag;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\DocChunk;
use Innertia\Ai\Models\IndexRun;
use Innertia\Ai\Models\RagCollection;
use Innertia\Ai\Services\Chunker;

/**
 * Indexa texto en una colección RAG: chunk → embed → insert. Driver-aware:
 * en pgsql inserta la columna embedding con cast ::vector; en sqlite guarda el
 * literal como texto (fallback para que la suite corra sin pgvector).
 * Registra un IndexRun por operación (auditoría).
 */
class RagIndexer
{
    public function __construct(private ?Chunker $chunker = null)
    {
        $this->chunker ??= new Chunker;
    }

    public function ingestText(string $collectionKey, string $text, array $metadata = [], ?string $triggeredBy = null): IndexRun
    {
        $collection = RagCollection::query()->where('key', $collectionKey)->firstOrFail();

        $run = IndexRun::create([
            'collection_id' => $collection->id,
            'status'        => 'running',
            'started_at'    => now(),
            'triggered_by'  => $triggeredBy,
        ]);

        try {
            $chunks  = $this->chunker->chunk($text);
            $vectors = $chunks === [] ? [] : Ai::embedder($collectionKey)->embedDocuments($chunks);

            $isPg = DB::connection()->getDriverName() === 'pgsql';

            foreach ($chunks as $i => $chunk) {
                $vector  = $vectors[$i] ?? [];
                $literal = '[' . implode(',', array_map(fn ($v) => (float) $v, $vector)) . ']';

                if ($isPg) {
                    DB::insert(
                        'INSERT INTO ai_doc_chunks (id, collection_id, content, metadata, token_count, embedding, created_at)
                         VALUES (?, ?, ?, ?, ?, ?::vector, ?)',
                        [(string) Str::uuid(), $collection->id, $chunk, json_encode($metadata), mb_strlen($chunk), $literal, now()]
                    );
                } else {
                    DocChunk::create([
                        'collection_id' => $collection->id,
                        'content'       => $chunk,
                        'metadata'      => $metadata,
                        'token_count'   => mb_strlen($chunk),
                        'embedding'     => $literal,
                        'created_at'    => now(),
                    ]);
                }
            }

            $run->update(['status' => 'done', 'finished_at' => now(), 'chunks_count' => count($chunks)]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
            throw $e;
        }

        return $run->fresh();
    }
}
