<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasUuid;

/** Invoice bitácora: created, issued, sent, payment_applied, overdue, voided… */
class InvoiceEvent extends Model
{
    use HasUuid;

    protected $table = 'billing_invoice_events';

    public $timestamps = false;

    protected $fillable = ['invoice_id', 'event', 'user_id', 'data', 'trace_id', 'created_at'];

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];
}
