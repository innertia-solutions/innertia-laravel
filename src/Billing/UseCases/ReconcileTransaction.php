<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\BankTransaction;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Turns a bank movement into a client payment and applies it to the chosen open invoices, in order
 * (partial payment when it does not cover them; one movement can pay several invoices). What is left
 * stays as client credit (saldo a favor). Idempotent: one payment per movement (unique bank_transaction_id).
 */
class ReconcileTransaction extends UseCase
{
    /** @param string[] $invoiceIds */
    public function __construct(
        public readonly string $transactionId,
        public readonly string $clientId,
        public readonly array $invoiceIds = [],
        public readonly ?string $matchedBy = null,
        public readonly ?int $score = null,
        public readonly ?array $suggestions = null,
    ) {}

    public function execute(): BankTransaction
    {
        BillingLog::ensureTrace();
        $userId = $this->matchedBy ?? (auth()->check() ? (string) auth()->id() : null);

        [$transaction, $payment] = DB::transaction(function () use ($userId) {
            $transaction = BankTransaction::query()->lockForUpdate()->findOrFail($this->transactionId);
            if (! $transaction->isOpen()) {
                throw new ConflictException('El movimiento ya fue conciliado o descartado.');
            }

            $invoices = collect($this->invoiceIds)->map(function ($id) {
                $invoice = Invoice::query()->where('client_id', $this->clientId)->find($id);
                if (! $invoice || ! $invoice->status->isOpen()) {
                    throw new ConflictException('Una de las facturas no está pendiente de pago.');
                }

                return $invoice;
            });

            $payment = Payment::create([
                'client_id'           => $this->clientId,
                'amount'              => $transaction->amount,
                'method'              => PaymentChannel::Transfer,
                'reference'           => mb_substr(trim(($transaction->reference ?? '') . ' ' . ($transaction->description ?? '')) ?: 'Cartola', 0, 120),
                'received_at'         => $transaction->posted_at->setTime(12, 0),
                'unallocated_amount'  => $transaction->amount,
                'created_by'          => $userId,
                'bank_transaction_id' => $transaction->id,
                'trace_id'            => traceId() ?: null,
            ]);

            foreach ($invoices as $invoice) {
                if ($payment->fresh()->unallocated_amount <= 0) {
                    break;
                }
                ApplyPayment::allocate($payment, $invoice);
            }

            $transaction->update([
                'status' => BankTransaction::MATCHED, 'client_id' => $this->clientId, 'payment_id' => $payment->id,
                'matched_by' => $userId, 'matched_at' => now(), 'score' => $this->score ?? $transaction->score,
                'suggestions' => $this->suggestions ?? $transaction->suggestions,
            ]);

            return [$transaction, $payment->fresh()];
        });

        (new RunDunning(clientId: $this->clientId, markOverdue: false))->execute();

        Activity::record('bank_transaction', $transaction->id, $this->matchedBy === 'auto' ? 'auto_matched' : 'matched', $this->clientId, [
            'amount' => $transaction->amount, 'posted_at' => $transaction->posted_at->format('Y-m-d'), 'payment_id' => $payment->id,
            'invoices' => Invoice::query()->whereIn('id', $this->invoiceIds)->get()->map->displayNumber()->all(),
            'credit' => $payment->unallocated_amount, 'score' => $this->score,
        ], null, $this->matchedBy === 'auto' ? null : $userId);
        BillingLog::info('bank movement reconciled', [
            'transaction_id' => $transaction->id, 'payment_id' => $payment->id, 'client_id' => $this->clientId,
            'auto' => $this->matchedBy === 'auto', 'credit' => $payment->unallocated_amount,
        ]);

        return $transaction->fresh();
    }
}
