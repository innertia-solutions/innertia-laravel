<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Platform\Traits\HasUuid;

/** Money received from a client. What is not allocated stays as credit (saldo a favor). */
class Payment extends Model
{
    use HasUuid;

    protected $table = 'billing_payments';

    protected $fillable = ['client_id', 'amount', 'method', 'reference', 'received_at', 'charge_id', 'unallocated_amount', 'created_by', 'trace_id'];

    protected $casts = [
        'amount'             => 'integer',
        'unallocated_amount' => 'integer',
        'method'             => PaymentChannel::class,
        'received_at'        => 'datetime',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'payment_id');
    }
}
