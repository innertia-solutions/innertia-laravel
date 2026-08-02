<?php

namespace Innertia\Payments\Providers;

use Illuminate\Support\Facades\DB;
use Innertia\Facades\Settings;
use Innertia\Payments\Contracts\PaymentGateway;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Enums\PaymentProvider;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Models\Payment;

class TransferProvider implements PaymentGateway
{
    /** El pagador declara haber transferido → charge a awaiting_confirmation. */
    public function declare(Charge $charge, array $data = []): Payment
    {
        return DB::transaction(function () use ($charge, $data) {
            $mode = (string) Settings::get('payments.collection_mode', 'direct');
            $payment = Payment::create([
                'charge_id'          => $charge->id,
                'provider'           => PaymentProvider::Transfer->value,
                'amount'             => $charge->amount,
                'external_reference' => $data['external_reference'] ?? null,
                'collection_mode'    => $mode,
                'commission_rate'    => $this->deriveCommissionRate($charge),
                'declared_at'        => now(),
            ]);
            $charge->transitionTo(ChargeStatus::AwaitingConfirmation);
            return $payment;
        });
    }

    /** Alias del contrato: para transferencia, createPayment = declare sin datos. */
    public function createPayment(Charge $charge): Payment
    {
        return $this->declare($charge);
    }

    /** El tenant confirma la recepción → charge a paid. Idempotente. */
    public function confirm(Payment $payment): void
    {
        $transitioned = DB::transaction(function () use ($payment) {
            $payment->update(['confirmed_via' => 'manual', 'paid_at' => now()]);
            if ($payment->charge->status !== ChargeStatus::Paid) {
                $payment->charge->transitionTo(ChargeStatus::Paid);
                return true;
            }
            return false;
        });

        if ($transitioned) {
            event(new PaymentPaid($payment->fresh()->charge));
        }
    }

    /** Transferencia no tiene webhook: no-op. */
    public function handleWebhook(array $payload): ?Charge
    {
        return null;
    }

    /** Transferencia no valida firma. */
    public function verifySignature(string $signatureHeader = '', string $requestId = '', string $dataId = ''): bool
    {
        return true;
    }

    /** commission_rate = application_fee / amount (snapshot), o null. */
    protected function deriveCommissionRate(Charge $charge): ?float
    {
        return $charge->application_fee !== null && $charge->amount > 0
            ? round($charge->application_fee / $charge->amount, 4)
            : null;
    }
}
