<?php

namespace Innertia\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Platform\Traits\HasUuid;
use Innertia\Saas\Enums\InvoicingMode;

/** The organization we have a contract with. Owns one or more spaces (tenants). */
class Client extends Model
{
    use HasUuid;

    public const BILLING_FIELDS = ['legal_name', 'tax_id', 'business_activity', 'address', 'billing_email'];

    protected $fillable = [
        'key', 'name', 'legal_name', 'tax_id', 'business_activity', 'address', 'billing_email',
        'invoicing_mode', 'payment_terms_days', 'grace_days', 'late_fee_percent', 'status', 'notes',
    ];

    protected $casts = [
        'invoicing_mode'     => InvoicingMode::class,
        'payment_terms_days' => 'integer',
        'grace_days'         => 'integer',
        'late_fee_percent'   => 'decimal:2',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(config('innertia.saas.tenant_model') ?? Tenant::class, 'client_id');
    }

    /** @return array<string, ?string> */
    public function billingData(): array
    {
        return $this->only(self::BILLING_FIELDS);
    }
}
