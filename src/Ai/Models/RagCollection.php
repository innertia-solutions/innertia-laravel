<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * RagCollection — una colección de documentos vectorizados para RAG.
 */
class RagCollection extends Model
{
    use HasUuids;

    protected $table = 'ai_rag_collections';

    protected $fillable = [
        'key',
        'name',
        'embedding_provider_id',
        'embedding_model',
        'dimension',
        'active',
    ];

    protected $casts = [
        'dimension' => 'integer',
        'active'    => 'boolean',
    ];

    public function chunks()
    {
        return $this->hasMany(DocChunk::class, 'collection_id');
    }

    public function embeddingProvider()
    {
        return $this->belongsTo(AiProvider::class, 'embedding_provider_id');
    }
}
