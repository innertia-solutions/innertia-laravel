<?php

namespace Innertia\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Innertia\Platform\Traits\HasTenant;

class Payment extends Model
{
    use HasUuids, HasTenant;

    protected $fillable = [
        'tenant_id', 'charge_id', 'provider', 'confirmed_via', 'amount',
        'external_reference', 'collection_mode', 'commission_rate',
        'link', 'link_expires_at', 'declared_at', 'paid_at',
    ];

    protected $casts = [
        'amount'          => 'integer',
        'commission_rate' => 'decimal:4',
        'link_expires_at' => 'datetime',
        'declared_at'     => 'datetime',
        'paid_at'         => 'datetime',
    ];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
