<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasUuid;

/** Billing activity log (audit): who did what on a client's collections, with trace_id. */
class Activity extends Model
{
    use HasUuid;

    protected $table = 'billing_activity';

    public $timestamps = false;

    protected $fillable = ['client_id', 'tenant_id', 'subject_type', 'subject_id', 'action', 'user_id', 'data', 'trace_id', 'created_at'];

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];

    public static function record(string $subjectType, ?string $subjectId, string $action, ?string $clientId, array $data = [], ?string $tenantId = null, ?string $userId = null): self
    {
        return self::create([
            'client_id'    => $clientId,
            'tenant_id'    => $tenantId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'action'       => $action,
            'user_id'      => $userId ?? (auth()->check() ? (string) auth()->id() : null),
            'data'         => $data ?: null,
            'trace_id'     => traceId() ?: null,
            'created_at'   => now(),
        ]);
    }
}
