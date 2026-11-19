<?php

namespace Innertia\PublicApi\Models;

use Illuminate\Database\Eloquent\Model;

/** Stored response for an `Idempotency-Key` (per API key, 24 h). */
class IdempotencyKey extends Model
{
    protected $table = 'api_idempotency_keys';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'api_key_id', 'idempotency_key', 'method', 'path', 'request_hash',
        'response_status', 'response_body', 'response_headers', 'created_at', 'expires_at',
    ];

    protected $casts = ['response_headers' => 'array', 'created_at' => 'datetime', 'expires_at' => 'datetime'];
}
