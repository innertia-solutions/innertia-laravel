<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * DocChunk — un fragmento de documento con su embedding.
 *
 * La columna `embedding` es driver-aware (vector en pgsql, text en sqlite).
 * Solo tiene `created_at` → $timestamps = false.
 */
class DocChunk extends Model
{
    use HasUuids;

    protected $table = 'ai_doc_chunks';

    public $timestamps = false;

    protected $fillable = [
        'collection_id',
        'content',
        'metadata',
        'token_count',
        'embedding',
        'created_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'token_count' => 'integer',
        'created_at'  => 'datetime',
    ];

    public function collection()
    {
        return $this->belongsTo(RagCollection::class, 'collection_id');
    }
}
