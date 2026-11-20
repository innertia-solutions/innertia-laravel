<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Registers money received from a client (transfer registered by hand, or a paid link) and applies it.
 * Idempotent by charge_id for payment links (webhooks may repeat).
 */
class RegisterPayment extends UseCase
{
    public function __construct(
        public readonly string $clientId,
        public readonly int $amount,
        public readonly PaymentChannel $method = PaymentChannel::Transfer,
        public readonly ?string $reference = null,
        public readonly ?string $receivedAt = null,
        public readonly ?string $invoiceId = null,
        public readonly ?string $chargeId = null,
        public readonly ?string $createdBy = null,
    ) {}

    public function execute(): Payment
    {
        BillingLog::ensureTrace();

        if ($this->amount <= 0) {
            throw new ConflictException('El monto debe ser mayor a cero.');
        }
        if ($this->chargeId !== null && ($existing = Payment::query()->where('charge_id', $this->chargeId)->first())) {
            return $existing;
        }

        $invoice = null;
        if ($this->invoiceId !== null) {
            $invoice = Invoice::query()->where('client_id', $this->clientId)->find($this->invoiceId);
            if (! $invoice || ! $invoice->status->isOpen()) {
                throw new ConflictException('La factura no está pendiente de pago.');
            }
        }

        $payment = Payment::create([
            'client_id'          => $this->clientId,
            'amount'             => $this->amount,
            'method'             => $this->method,
            'reference'          => $this->reference,
            'received_at'        => $this->receivedAt ?? now(),
            'charge_id'          => $this->chargeId,
            'unallocated_amount' => $this->amount,
            'created_by'         => $this->createdBy ?? (auth()->check() ? (string) auth()->id() : null),
            'trace_id'           => traceId() ?: null,
        ]);

        BillingLog::info('payment registered', [
            'payment_id' => $payment->id, 'client_id' => $this->clientId, 'amount' => $this->amount,
            'method' => $this->method->value, 'invoice_id' => $this->invoiceId,
        ]);

        return (new ApplyPayment($payment, $invoice))->execute();
    }
}
