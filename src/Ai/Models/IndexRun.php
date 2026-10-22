<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * IndexRun — una ejecución de indexado/embeddings sobre una RagCollection.
 */
class IndexRun extends Model
{
    use HasUuids;

    protected $table = 'ai_index_runs';

    protected $fillable = [
        'collection_id',
        'status',
        'started_at',
        'finished_at',
        'chunks_count',
        'error',
        'triggered_by',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'chunks_count' => 'integer',
    ];

    public function collection()
    {
        return $this->belongsTo(RagCollection::class, 'collection_id');
    }
}
