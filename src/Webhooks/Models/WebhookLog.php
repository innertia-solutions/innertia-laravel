<?php

namespace Innertia\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    protected $fillable = [
        'webhook_id',
        'event_key',
        'payload',
        'response_status',
        'response_body',
        'attempts',
        'delivered_at',
        'failed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'delivered_at' => 'datetime',
        'failed_at'    => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function wasDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function failed(): bool
    {
        return $this->failed_at !== null;
    }
}
