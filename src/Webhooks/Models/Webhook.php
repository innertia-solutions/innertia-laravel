<?php

namespace Innertia\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    protected $fillable = [
        'url',
        'description',
        'events',
        'secret',
        'active',
    ];

    public function getFillable(): array
    {
        $fillable = parent::getFillable();

        if (config('innertia.mode') === 'saas') {
            array_unshift($fillable, 'tenant_id');
        }

        return $fillable;
    }

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
