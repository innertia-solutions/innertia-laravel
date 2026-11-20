<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/** Cancels a pending adjustment (not yet consumed, not a credit note already applied). Closed charges keep it. */
class CancelAdjustment extends UseCase
{
    public function __construct(public readonly string $adjustmentId, public readonly string $reason) {}

    public function execute(): Adjustment
    {
        BillingLog::ensureTrace();
        $adjustment = Adjustment::query()->findOrFail($this->adjustmentId);

        if ($adjustment->canceled_at !== null) {
            throw new ConflictException('El ajuste ya está anulado.');
        }
        if ($adjustment->consumed_at !== null || $adjustment->payment_id !== null) {
            throw new ConflictException('El ajuste ya se aplicó; usa una nota de crédito o un nuevo ajuste.');
        }

        $userId = auth()->check() ? (string) auth()->id() : null;
        $adjustment->update(['canceled_at' => now(), 'canceled_by' => $userId, 'cancel_reason' => $this->reason]);

        Activity::record($adjustment->isCreditNote() ? 'credit_note' : 'adjustment', $adjustment->id, 'canceled',
            $adjustment->client_id, ['reason' => $this->reason], $adjustment->tenant_id, $userId);
        BillingLog::info('adjustment canceled', ['adjustment_id' => $adjustment->id]);

        return $adjustment;
    }
}
