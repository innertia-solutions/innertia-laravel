<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Billing\Enums\AdjustmentType;
use Innertia\Platform\Traits\HasUuid;

/**
 * Discount, surcharge, one-off charge or credit of a space (tenant_id) or of a whole client (client_id,
 * tenant_id null: percent discounts/surcharges applied to every space). Applied at the close.
 * A credit note is an Adjustment of type credit with a number (NC-000001) and a target:
 *   next_period → a line of the next charge of the space; invoice → applied to an open invoice
 *   (payment_id, a non-cash Payment of method credit); balance → client credit (saldo a favor).
 */
class Adjustment extends Model
{
    use HasUuid;

    protected $table = 'billing_adjustments';

    protected $fillable = [
        'tenant_id', 'client_id', 'type', 'amount', 'percent', 'recurring', 'starts_at', 'ends_at', 'reason', 'created_by',
        'consumed_at', 'invoice_id', 'payment_id', 'number', 'target', 'canceled_at', 'canceled_by', 'cancel_reason', 'trace_id',
    ];

    protected $casts = [
        'type'        => AdjustmentType::class,
        'amount'      => 'integer',
        'percent'     => 'float',
        'recurring'   => 'boolean',
        'starts_at'   => \Innertia\Billing\Support\LocalDate::class,
        'ends_at'     => \Innertia\Billing\Support\LocalDate::class,
        'consumed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'number'      => 'integer',
    ];

    public function isCreditNote(): bool
    {
        return $this->number !== null;
    }

    /** NC-000001 (prefix innertia.billing.credit_note_prefix). */
    public function displayNumber(): ?string
    {
        return $this->number === null ? null
            : config('innertia.billing.credit_note_prefix', 'NC-') . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }
}
