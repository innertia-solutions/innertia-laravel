<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    protected $fillable = [
        'tenant_id',
        'url',
        'description',
        'events',
        'secret',
        'active',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    public function matchesEvent(string $eventKey): bool
    {
        return in_array('*', $this->events, true)
            || in_array($eventKey, $this->events, true);
    }
}
