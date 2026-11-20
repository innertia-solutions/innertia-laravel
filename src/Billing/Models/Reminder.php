<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasUuid;

/** A payment reminder sent for an invoice. unique(invoice_id, kind, sequence) = never sent twice. */
class Reminder extends Model
{
    use HasUuid;

    protected $table = 'billing_reminders';

    public $timestamps = false;

    protected $fillable = ['invoice_id', 'kind', 'sequence', 'sent_on', 'email', 'escalated', 'trace_id', 'created_at'];

    protected $casts = [
        'sequence'   => 'integer',
        'sent_on'    => \Innertia\Billing\Support\LocalDate::class,
        'escalated'  => 'boolean',
        'created_at' => 'datetime',
    ];
}
