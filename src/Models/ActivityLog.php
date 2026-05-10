<?php

namespace Innertia\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'action',
        'entity_type',
        'entity_id',
        'user_id',
        'trace_id',
        'metadata',
        'description',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function forEntity(string $entityType, string $entityId)
    {
        return static::where('entity_type', 'like', '%\\'.class_basename($entityType))
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'desc');
    }
}
