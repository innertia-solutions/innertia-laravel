<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasUuid;

/**
 * Late fee interest charged to a space for an overdue invoice over a range of days (from..to, local
 * dates). Billed as a `late_fee` line of the space's statement (statement_id). Waived ones keep the row.
 */
class LateFee extends Model
{
    use HasUuid;

    protected $table = 'billing_late_fees';

    protected $fillable = [
        'invoice_id', 'tenant_id', 'statement_id', 'from_date', 'to_date', 'days', 'base', 'rate', 'amount',
        'waived_at', 'waived_by', 'waive_reason', 'trace_id',
    ];

    protected $casts = [
        'from_date' => \Innertia\Billing\Support\LocalDate::class,
        'to_date'   => \Innertia\Billing\Support\LocalDate::class,
        'days'      => 'integer',
        'base'      => 'integer',
        'rate'      => 'float',
        'amount'    => 'integer',
        'waived_at' => 'datetime',
    ];
}
