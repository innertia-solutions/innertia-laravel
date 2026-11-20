<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasUuid;

/**
 * Price of a meter in a plan. Quantity meters: (quantity − included) × unit_price.
 * Cost-valued meters (ai.cost): cost × (1 + markup_percent/100) − included_quantity (an amount).
 */
class PlanMeter extends Model
{
    use HasUuid;

    protected $table = 'billing_plan_meters';

    protected $fillable = ['plan_id', 'meter', 'included_quantity', 'unit_price', 'markup_percent'];

    protected $casts = [
        'included_quantity' => 'float',
        'unit_price'        => 'integer',
        'markup_percent'    => 'float',
    ];
}
