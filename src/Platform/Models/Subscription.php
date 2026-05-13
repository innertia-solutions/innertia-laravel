<?php

namespace Innertia\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Subscription extends Model
{
    protected $fillable = [
        'subscriber_id',
        'subscribable_type',
        'subscribable_id',
        'events',
        'channels',
    ];

    protected $casts = [
        'events'   => 'array',
        'channels' => 'array',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'subscriber_id');
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function matchesEvent(string $eventKey): bool
    {
        return in_array('*', $this->events, true)
            || in_array($eventKey, $this->events, true);
    }
}
