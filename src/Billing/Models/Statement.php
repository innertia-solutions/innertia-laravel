<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Platform\Traits\HasUuid;

/** The charge of one space for one period (unique tenant_id + period_start). */
class Statement extends Model
{
    use HasUuid;

    protected $table = 'billing_statements';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'plan_id', 'period_start', 'period_end', 'currency', 'tax_rate',
        'subtotal', 'tax', 'total', 'invoice_id', 'trace_id',
    ];

    protected $casts = [
        'period_start' => \Innertia\Billing\Support\LocalDate::class,
        'period_end'   => \Innertia\Billing\Support\LocalDate::class,
        'tax_rate'     => 'float',
        'subtotal'     => 'integer',
        'tax'          => 'integer',
        'total'        => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class, 'statement_id')->orderBy('position');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class, 'tenant_id');
    }
}
