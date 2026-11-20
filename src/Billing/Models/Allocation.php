<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasUuid;

class Allocation extends Model
{
    use HasUuid;

    protected $table = 'billing_allocations';

    public $timestamps = false;

    protected $fillable = ['payment_id', 'invoice_id', 'amount', 'created_by', 'created_at'];

    protected $casts = ['amount' => 'integer', 'created_at' => 'datetime'];
}
