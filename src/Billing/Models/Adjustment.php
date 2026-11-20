<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Billing\Enums\AdjustmentType;
use Innertia\Platform\Traits\HasUuid;

/** Discount, surcharge, one-off charge or credit of a space (managed from stage 3; applied at the close). */
class Adjustment extends Model
{
    use HasUuid;

    protected $table = 'billing_adjustments';

    protected $fillable = ['tenant_id', 'type', 'amount', 'percent', 'recurring', 'starts_at', 'ends_at', 'reason', 'created_by', 'consumed_at'];

    protected $casts = [
        'type'        => AdjustmentType::class,
        'amount'      => 'integer',
        'percent'     => 'float',
        'recurring'   => 'boolean',
        'starts_at'   => \Innertia\Billing\Support\LocalDate::class,
        'ends_at'     => \Innertia\Billing\Support\LocalDate::class,
        'consumed_at' => 'datetime',
    ];
}
