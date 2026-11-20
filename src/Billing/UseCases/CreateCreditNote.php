<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\AdjustmentType;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Credit note (NC-000001) with reason and author, applied to:
 *   invoice     → an open invoice (amount with VAT): a non-cash Payment (method credit) allocated to it;
 *                 what exceeds its balance stays as client credit.
 *   balance     → client credit (saldo a favor), applied to the next issued invoice.
 *   next_period → a credit line (net, before VAT) of the next charge of the space (tenantId required).
 */
class CreateCreditNote extends UseCase
{
    public const TARGETS = ['invoice', 'balance', 'next_period'];

    public function __construct(
        public readonly string $clientId,
        public readonly int $amount,
        public readonly string $reason,
        public readonly string $target = 'invoice',
        public readonly ?string $invoiceId = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $createdBy = null,
    ) {}

    public function execute(): Adjustment
    {
        BillingLog::ensureTrace();

        if (! in_array($this->target, self::TARGETS, true)) {
            throw new ConflictException('Destino de la nota de crédito no válido.');
        }
        if ($this->amount <= 0) {
            throw new ConflictException('El monto debe ser mayor a cero.');
        }
        $userId = $this->createdBy ?? (auth()->check() ? (string) auth()->id() : null);

        $invoice = null;
        if ($this->target === 'invoice') {
            $invoice = Invoice::query()->where('client_id', $this->clientId)->find($this->invoiceId);
            if (! $invoice || ! $invoice->status->isOpen()) {
                throw new ConflictException('La factura no está pendiente de pago.');
            }
        }
        if ($this->target === 'next_period' && $this->tenantId === null) {
            throw new ConflictException('Indica el espacio al que se descuenta el próximo cobro.');
        }

        $note = $this->createWithNumber(fn (int $number) => Adjustment::create([
            'tenant_id'  => $this->target === 'next_period' ? $this->tenantId : ($invoice?->tenant_id ?? $this->tenantId),
            'client_id'  => $this->clientId,
            'type'       => AdjustmentType::Credit,
            'amount'     => $this->amount,
            'recurring'  => false,
            'reason'     => $this->reason,
            'number'     => $number,
            'target'     => $this->target,
            'invoice_id' => $invoice?->id,
            'created_by' => $userId,
            // Only next_period notes go through the close; the others are settled now.
            'consumed_at'=> $this->target === 'next_period' ? null : now(),
            'trace_id'   => traceId() ?: null,
        ]));

        if ($this->target !== 'next_period') {
            $payment = Payment::create([
                'client_id'          => $this->clientId,
                'amount'             => $this->amount,
                'method'             => PaymentChannel::Credit,
                'reference'          => $note->displayNumber(),
                'received_at'        => now(),
                'unallocated_amount' => $this->amount,
                'created_by'         => $userId,
                'trace_id'           => traceId() ?: null,
            ]);
            $note->update(['payment_id' => $payment->id]);

            if ($invoice) {
                $applied = ApplyPayment::allocate($payment, $invoice, 'credit_note');
                $invoice->log('credit_note', ['number' => $note->displayNumber(), 'amount' => $applied, 'reason' => $this->reason], $userId);
                (new RunDunning(clientId: $this->clientId, markOverdue: false))->execute();
            }
        }

        Activity::record('credit_note', $note->id, 'created', $this->clientId, [
            'number' => $note->displayNumber(), 'amount' => $this->amount, 'target' => $this->target,
            'invoice_id' => $invoice?->id, 'invoice' => $invoice?->displayNumber(), 'reason' => $this->reason,
        ], $note->tenant_id, $userId);
        BillingLog::info('credit note created', ['adjustment_id' => $note->id, 'number' => $note->number, 'target' => $this->target, 'amount' => $this->amount]);

        return $note->fresh();
    }

    /** next number = max + 1, retried on a unique collision. */
    private function createWithNumber(\Closure $create): Adjustment
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn () => $create((int) Adjustment::query()->max('number') + 1));
            } catch (QueryException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }
}
