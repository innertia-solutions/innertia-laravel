<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Web notification center record.
 * Created by DomainEventRouter when a DomainEvent uses the 'web' channel.
 * Immutable — no updated_at.
 */
class Notification extends Model
{
    use HasUuids;

    protected $table = 'user_notifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'key',
        'title',
        'body',
        'data',
        'read_at',
        'created_at',
    ];

    protected $casts = [
        'data'       => 'array',
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (! $model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function markRead(): void
    {
        if (! $this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
