<?php

namespace Innertia\Payments\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Innertia\Facades\Settings;
use Innertia\Payments\Contracts\PaymentGateway;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Enums\PaymentProvider;
use Innertia\Payments\Events\PaymentFailed;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Models\Payment;

/**
 * Flow.cl — modo directo. Link firmado (HMAC-SHA256), confirma vía webhook (getStatus).
 * Credenciales por tenant (Settings payments.flow.api_key/secret_key). Base en
 * config services.flow.base_url.
 */
class FlowProvider implements PaymentGateway
{
    public function createPayment(Charge $charge): Payment
    {
        $existing = Payment::where('charge_id', $charge->id)
            ->where('provider', PaymentProvider::Flow->value)
            ->whereNull('paid_at')
            ->latest()->first();

        if ($existing && $existing->link && (! $existing->link_expires_at || $existing->link_expires_at->isFuture())) {
            return $existing;
        }

        [$apiKey] = $this->creds();

        $params = [
            'apiKey'          => $apiKey,
            'commerceOrder'   => (string) $charge->id,
            'subject'         => $charge->metadata['concept'] ?? 'Cobro',
            'amount'          => $charge->amount,
            'currency'        => $charge->currency ?: 'CLP',
            'email'           => $this->payerEmail($charge),
            'urlConfirmation' => url('api/payments/webhook/flow'),
            'urlReturn'       => url('api/payments/return/flow'),
        ];
        $params['s'] = $this->sign($params);

        $res = Http::asForm()->post($this->base() . '/payment/create', $params)->throw()->json();

        $expiresAt = now()->addDays(3);
        $mode = (string) Settings::get('payments.collection_mode', 'direct');

        return Payment::create([
            'charge_id'          => $charge->id,
            'provider'           => PaymentProvider::Flow->value,
            'amount'             => $charge->amount,
            'external_reference' => $res['token'] ?? null,
            'collection_mode'    => $mode,
            'commission_rate'    => $this->deriveCommissionRate($charge),
            'link'               => ($res['url'] ?? '') . '?token=' . ($res['token'] ?? ''),
            'link_expires_at'    => $expiresAt,
        ]);
    }

    public function confirm(Payment $payment): void
    {
        if ($payment->paid_at) {
            return;
        }

        DB::transaction(function () use ($payment) {
            $payment->update(['confirmed_via' => 'webhook', 'paid_at' => now()]);
            if ($payment->charge->status !== ChargeStatus::Paid) {
                $payment->charge->transitionTo(ChargeStatus::Paid);
            }
        });

        event(new PaymentPaid($payment->fresh()->charge, $payment->fresh()));
    }

    public function handleWebhook(array $payload): ?Charge
    {
        $token = $payload['token'] ?? null;
        if (! $token) {
            return null;
        }

        $payment = Payment::where('provider', PaymentProvider::Flow->value)
            ->where('external_reference', $token)
            ->latest()->first();
        if (! $payment) {
            return null;
        }

        $flowStatus = $this->status($token);

        if ($flowStatus === 2) {
            $this->confirm($payment);
        } elseif ($flowStatus === 3 && $payment->charge?->status === ChargeStatus::Pending) {
            $payment->charge->transitionTo(ChargeStatus::Failed);
            event(new PaymentFailed($payment->fresh()->charge, $payment->fresh()));
        }

        return $payment->charge;
    }

    public function verifySignature(string $signatureHeader = '', string $requestId = '', string $dataId = ''): bool
    {
        // Flow no firma el webhook con header; la validez se confirma consultando getStatus.
        return true;
    }

    protected function status(string $token): ?int
    {
        [$apiKey] = $this->creds();

        $params = ['apiKey' => $apiKey, 'token' => $token];
        $params['s'] = $this->sign($params);

        $res = Http::asForm()->post($this->base() . '/payment/getStatus', $params)->throw()->json();

        return isset($res['status']) ? (int) $res['status'] : null;
    }

    /** Firma Flow: params ordenados por nombre, concatenados name.value, HMAC-SHA256 hex. */
    protected function sign(array $params): string
    {
        [, $secret] = $this->creds();

        ksort($params);
        $s = '';
        foreach ($params as $name => $value) {
            $s .= $name . $value;
        }

        return hash_hmac('sha256', $s, (string) $secret);
    }

    /** @return array{0: ?string, 1: ?string} [apiKey, secretKey] del tenant. */
    protected function creds(): array
    {
        return [
            Settings::get('payments.flow.api_key'),
            Settings::get('payments.flow.secret_key'),
        ];
    }

    protected function base(): string
    {
        return rtrim((string) config('services.flow.base_url'), '/');
    }

    /** Email del pagador: metadata['email'] o el email del payer (morphTo). */
    protected function payerEmail(Charge $charge): string
    {
        return $charge->metadata['email'] ?? (string) optional($charge->payer)->email;
    }

    protected function deriveCommissionRate(Charge $charge): ?float
    {
        return $charge->application_fee !== null && $charge->amount > 0
            ? round($charge->application_fee / $charge->amount, 4)
            : null;
    }
}
