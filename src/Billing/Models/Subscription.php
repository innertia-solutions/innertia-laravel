<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Platform\Traits\HasUuid;

/**
 * One per production space (unique tenant_id). Period dates are local dates (billing time zone),
 * both inclusive. overrides: negotiated prices per field (base_price, seat_price, included_seats,
 * meters.{key}.unit_price|included_quantity|markup_percent).
 */
class Subscription extends Model
{
    use HasUuid;

    protected $table = 'billing_subscriptions';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'started_at', 'trial_ends_at', 'current_period_start', 'current_period_end',
        'payment_method', 'overrides', 'pending_plan_id', 'suspended_manually', 'suspended_at', 'canceled_at',
    ];

    protected $casts = [
        'status'               => SubscriptionStatus::class,
        'payment_method'       => PaymentMethod::class,
        'started_at'           => \Innertia\Billing\Support\LocalDate::class,
        'trial_ends_at'        => \Innertia\Billing\Support\LocalDate::class,
        'current_period_start' => \Innertia\Billing\Support\LocalDate::class,
        'current_period_end'   => \Innertia\Billing\Support\LocalDate::class,
        'overrides'            => 'array',
        'suspended_manually'   => 'boolean',
        'suspended_at'         => 'datetime',
        'canceled_at'          => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class, 'tenant_id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(Statement::class, 'subscription_id');
    }

    public function override(string $key, mixed $default = null): mixed
    {
        return data_get($this->overrides ?? [], $key, $default);
    }

    /** First local date that is billed (the day after the trial, or the start). */
    public function paidSince(): string
    {
        return $this->trial_ends_at
            ? $this->trial_ends_at->addDay()->format('Y-m-d')
            : $this->started_at->format('Y-m-d');
    }
}
