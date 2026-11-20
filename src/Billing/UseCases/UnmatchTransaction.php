<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\DB;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\BankTransaction;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Support\Allocations;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Undoes a reconciliation (wrong match): the payment created from the movement is removed, its
 * allocations reversed (invoices reopen) and the movement goes back to pending. Reason in the log.
 */
class UnmatchTransaction extends UseCase
{
    public function __construct(public readonly string $transactionId, public readonly string $reason) {}

    public function execute(): BankTransaction
    {
        BillingLog::ensureTrace();
        $userId = auth()->check() ? (string) auth()->id() : null;

        [$transaction, $clientId, $data] = DB::transaction(function () {
            $transaction = BankTransaction::query()->lockForUpdate()->findOrFail($this->transactionId);
            if ($transaction->status !== BankTransaction::MATCHED) {
                throw new ConflictException('El movimiento no está conciliado.');
            }

            $payment = $transaction->payment_id ? Payment::query()->find($transaction->payment_id) : null;
            $clientId = $transaction->client_id;
            $data = ['reason' => $this->reason, 'amount' => $transaction->amount, 'payment_id' => $payment?->id, 'matched_by' => $transaction->matched_by];
            if ($payment) {
                $data['invoices'] = Allocations::reversePayment($payment);
                $payment->delete();
            }

            $transaction->update([
                'status' => $transaction->suggestions ? BankTransaction::SUGGESTED : BankTransaction::UNMATCHED,
                'payment_id' => null, 'client_id' => null, 'matched_by' => null, 'matched_at' => null,
            ]);

            return [$transaction, $clientId, $data];
        });

        if ($clientId) {
            (new RunDunning(clientId: $clientId))->execute();
        }
        Activity::record('bank_transaction', $transaction->id, 'unmatched', $clientId, $data, null, $userId);
        BillingLog::info('bank movement unmatched', ['transaction_id' => $transaction->id] + $data);

        return $transaction->fresh();
    }
}
