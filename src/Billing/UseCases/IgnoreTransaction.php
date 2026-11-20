<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\BankTransaction;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/** Discards a movement that is not a client payment (or restores it with $restore). */
class IgnoreTransaction extends UseCase
{
    public function __construct(public readonly string $transactionId, public readonly ?string $reason = null, public readonly bool $restore = false) {}

    public function execute(): BankTransaction
    {
        $transaction = BankTransaction::query()->findOrFail($this->transactionId);

        if ($this->restore) {
            if ($transaction->status !== BankTransaction::IGNORED) {
                throw new ConflictException('El movimiento no está descartado.');
            }
            $transaction->update(['status' => $transaction->suggestions ? BankTransaction::SUGGESTED : BankTransaction::UNMATCHED, 'ignore_reason' => null]);
        } else {
            if (! $transaction->isOpen()) {
                throw new ConflictException('Solo se descartan movimientos pendientes.');
            }
            $transaction->update(['status' => BankTransaction::IGNORED, 'ignore_reason' => $this->reason]);
        }

        Activity::record('bank_transaction', $transaction->id, $this->restore ? 'restored' : 'ignored', null, ['reason' => $this->reason, 'amount' => $transaction->amount]);

        return $transaction->fresh();
    }
}
