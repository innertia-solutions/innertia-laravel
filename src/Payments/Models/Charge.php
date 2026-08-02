<?php

namespace Innertia\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Platform\Traits\BroadcastsEntityChanges;
use Innertia\Platform\Traits\HasTenant;

class Charge extends Model
{
    use HasUuids, HasTenant, BroadcastsEntityChanges;

    protected $fillable = [
        'tenant_id', 'payer_type', 'payer_id', 'billable_type', 'billable_id',
        'amount', 'currency', 'status', 'application_fee', 'metadata', 'due_at', 'paid_at',
    ];

    protected $casts = [
        'amount'          => 'integer',
        'application_fee' => 'integer',
        'metadata'        => 'array',
        'status'          => ChargeStatus::class,
        'due_at'          => 'datetime',
        'paid_at'         => 'datetime',
    ];

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function payer(): MorphTo
    {
        return $this->morphTo();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function transitionTo(ChargeStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new \DomainException("Transición inválida: {$this->status->value} → {$to->value}.");
        }
        $this->update(['status' => $to]);
    }
}
