<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Billing\Enums\BillingInterval;
use Innertia\Platform\Traits\HasUuid;

/** A price list: base price, seats and metered consumption. Money in minor units. */
class Plan extends Model
{
    use HasUuid;

    protected $table = 'billing_plans';

    protected $fillable = [
        'key', 'name', 'description', 'product', 'currency', 'interval', 'base_price', 'included_seats',
        'seat_price', 'trial_days', 'tax_rate', 'limits', 'active',
    ];

    protected $attributes = [
        'currency' => 'CLP', 'interval' => 'monthly', 'base_price' => 0, 'included_seats' => 0,
        'seat_price' => 0, 'trial_days' => 0, 'tax_rate' => 19, 'active' => true,
    ];

    protected $casts = [
        'interval'       => BillingInterval::class,
        'base_price'     => 'integer',
        'included_seats' => 'integer',
        'seat_price'     => 'integer',
        'trial_days'     => 'integer',
        'tax_rate'       => 'float',
        'limits'         => 'array',
        'active'         => 'boolean',
    ];

    public function meters(): HasMany
    {
        return $this->hasMany(PlanMeter::class, 'plan_id');
    }

    /** Base price normalized to one month (MRR). */
    public function monthlyBase(?array $overrides = null): int
    {
        $base = (int) ($overrides['base_price'] ?? $this->base_price);

        return (int) round($base / $this->interval->months());
    }
}
