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
        return collect($this->events)->some(function (string $pattern) use ($eventKey) {
            if ($pattern === '*') {
                return true;
            }

            if ($pattern === $eventKey) {
                return true;
            }

            // 'workflow.*'              → any event under workflow
            // 'workflow.transitioned.*' → any step of transitioned
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -2);
                return str_starts_with($eventKey, $prefix . '.');
            }

            return false;
        });
    }
}
