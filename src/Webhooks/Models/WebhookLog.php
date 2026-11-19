<?php

namespace Innertia\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt of a webhook message (`message_id` = `webhook-id` header; retries and resends share it).
 *
 * status: pending → delivered | retrying | failed (final).
 */
class WebhookLog extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'webhook_id',
        'event_key',
        'payload',
        'response_status',
        'response_body',
        'attempts',
        'delivered_at',
        'failed_at',
        'message_id',
        'status',
        'duration_ms',
        'error',
        'request_headers',
        'is_test',
        'trace_id',
        'next_retry_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'request_headers' => 'array',
        'is_test'         => 'boolean',
        'delivered_at'    => 'datetime',
        'failed_at'       => 'datetime',
        'next_retry_at'   => 'datetime',
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

    public function resolvedStatus(): string
    {
        return $this->status ?? match (true) {
            $this->delivered_at !== null => self::STATUS_DELIVERED,
            $this->failed_at !== null    => self::STATUS_FAILED,
            default                      => self::STATUS_PENDING,
        };
    }

    public function toApiArray(bool $detail = false): array
    {
        $data = [
            'id'              => $this->id,
            'message_id'      => $this->message_id,
            'event_key'       => $this->event_key,
            'status'          => $this->resolvedStatus(),
            'attempts'        => (int) $this->attempts,
            'response_status' => $this->response_status,
            'duration_ms'     => $this->duration_ms,
            'error'           => $this->error,
            'is_test'         => (bool) $this->is_test,
            'created_at'      => $this->created_at?->toIso8601String(),
            'delivered_at'    => $this->delivered_at?->toIso8601String(),
            'failed_at'       => $this->failed_at?->toIso8601String(),
            'next_retry_at'   => $this->next_retry_at?->toIso8601String(),
        ];

        if ($detail) {
            $data['payload'] = $this->payload;
            $data['request_headers'] = $this->request_headers;
            $data['response_body'] = $this->response_body;
        }

        return $data;
    }
}
