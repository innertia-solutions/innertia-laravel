<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasUuid;

/** A metered consumption. Idempotent by (meter, source_ref). cost: minor units for cost-valued meters. */
class UsageRecord extends Model
{
    use HasUuid;

    protected $table = 'billing_usage_records';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'meter', 'quantity', 'cost', 'occurred_at', 'source_ref', 'meta', 'statement_id', 'created_at'];

    protected $casts = [
        'quantity'    => 'float',
        'cost'        => 'integer',
        'occurred_at' => 'datetime',
        'meta'        => 'array',
        'created_at'  => 'datetime',
    ];
}
