<?php

namespace Innertia\Payments\Providers;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Settings;
use Innertia\Payments\Contracts\PaymentGateway;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Enums\PaymentProvider;
use Innertia\Payments\Events\PaymentFailed;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Models\Payment;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

/**
 * MercadoPago — Checkout Pro. Link de cobro (Preference → init_point). Credenciales por
 * tenant (Settings payments.mercadopago.access_token). Sin token → link mock. Split
 * (marketplace) usa el token del seller + `application_fee` del charge como marketplace_fee.
 */
class MercadoPagoProvider implements PaymentGateway
{
    public function isConfigured(): bool
    {
        return (bool) $this->accessToken();
    }

    public function createPayment(Charge $charge): Payment
    {
        $existing = Payment::where('charge_id', $charge->id)
            ->where('provider', PaymentProvider::MercadoPago->value)
            ->whereNull('paid_at')
            ->latest()->first();

        if ($existing && $existing->link && (! $existing->link_expires_at || $existing->link_expires_at->isFuture())) {
            return $existing;
        }

        $expiresAt = now()->addDays(3);

        if ($this->isSplit()) {
            $link = $this->createSplitPreferenceLink($charge, $expiresAt);
        } else {
            $link = $this->isConfigured()
                ? $this->createPreferenceLink($charge, $expiresAt)
                : "/checkout/mock/{$charge->id}";
        }

        $mode = (string) Settings::get('payments.collection_mode', 'direct');

        return Payment::create([
            'charge_id'          => $charge->id,
            'provider'           => PaymentProvider::MercadoPago->value,
            'amount'             => $charge->amount,
            'external_reference' => (string) $charge->id,
            'collection_mode'    => $mode,
            'commission_rate'    => $this->deriveCommissionRate($charge),
            'link'               => $link,
            'link_expires_at'    => $expiresAt,
        ]);
    }

    protected function createPreferenceLink(Charge $charge, CarbonInterface $expiresAt): string
    {
        $token = $this->accessToken();
        $this->configureSdk($token);

        $preference = (new PreferenceClient)->create([
            'items' => [[
                'title'       => $charge->metadata['concept'] ?? 'Cobro',
                'quantity'    => 1,
                'unit_price'  => (float) $charge->amount,
                'currency_id' => $charge->currency ?: 'CLP',
            ]],
            'external_reference' => (string) $charge->id,
            'notification_url'   => url('api/payments/webhook/mercadopago'),
            'expiration_date_to' => $expiresAt->toIso8601String(),
            'expires'            => true,
        ]);

        return str_starts_with((string) $token, 'TEST-')
            ? ($preference->sandbox_init_point ?? $preference->init_point)
            : $preference->init_point;
    }

    /**
     * Preference en modo MARKETPLACE (split): cobra con el access_token del seller y añade
     * `marketplace_fee` = `application_fee` del charge (lo calcula el producto). Sin token
     * de seller o sin application_fee → ForbiddenException.
     */
    protected function createSplitPreferenceLink(Charge $charge, CarbonInterface $expiresAt): string
    {
        $sellerToken = $this->sellerAccessToken();
        if (! $sellerToken) {
            throw new ForbiddenException('Conecta la cuenta de MercadoPago para cobrar en modo split.');
        }

        if ($charge->application_fee === null) {
            throw new ForbiddenException('Falta application_fee en el charge para el cobro split.');
        }

        $response = Http::withToken($sellerToken)
            ->post('https://api.mercadopago.com/checkout/preferences', [
                'items' => [[
                    'title'       => $charge->metadata['concept'] ?? 'Cobro',
                    'quantity'    => 1,
                    'unit_price'  => (float) $charge->amount,
                    'currency_id' => $charge->currency ?: 'CLP',
                ]],
                'marketplace_fee'    => (int) $charge->application_fee,
                'external_reference' => (string) $charge->id,
                'notification_url'   => url('api/payments/webhook/mercadopago'),
                'expiration_date_to' => $expiresAt->toIso8601String(),
                'expires'            => true,
            ])->throw()->json();

        return str_starts_with($sellerToken, 'TEST-')
            ? ($response['sandbox_init_point'] ?? $response['init_point'])
            : ($response['init_point'] ?? $response['sandbox_init_point']);
    }

    public function confirm(Payment $payment): void
    {
        if ($payment->paid_at) {
            return;
        }

        $transitioned = DB::transaction(function () use ($payment) {
            $payment->update(['confirmed_via' => 'webhook', 'paid_at' => now()]);
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

    /** Red de seguridad split: reconcilia pagos pendientes contra MP. Devuelve # confirmados. */
    public function reconcile(): int
    {
        if (! $this->isSplit()) {
            return 0;
        }

        $sellerToken = $this->sellerAccessToken();
        if (! $sellerToken) {
            return 0;
        }

        $confirmed = 0;

        $pending = Payment::query()
            ->where('provider', PaymentProvider::MercadoPago->value)
            ->whereNull('paid_at')
            ->whereHas('charge', fn ($q) => $q->where('status', ChargeStatus::Pending->value))
            ->with('charge')
            ->latest()
            ->limit(200)
            ->get()
            ->unique('charge_id');

        foreach ($pending as $payment) {
            if (! $payment->charge || $payment->charge->status === ChargeStatus::Paid) {
                continue;
            }

            $status = $this->searchPaymentStatus($sellerToken, (string) $payment->charge_id);
            if ($status === 'approved') {
                $this->confirm($payment);
                $confirmed++;
            }
        }

        return $confirmed;
    }

    protected function searchPaymentStatus(string $sellerToken, string $externalReference): ?string
    {
        try {
            $results = Http::withToken($sellerToken)
                ->get('https://api.mercadopago.com/v1/payments/search', [
                    'external_reference' => $externalReference,
                    'sort'               => 'date_created',
                    'criteria'           => 'desc',
                ])->throw()->json('results', []);

            return $results[0]['status'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Simula la aprobación desde el checkout mock (sin credenciales). */
    public function mockApprove(Charge $charge): void
    {
        $payment = Payment::where('charge_id', $charge->id)
            ->where('provider', PaymentProvider::MercadoPago->value)
            ->latest()->first() ?? $this->createPayment($charge);

        $this->confirm($payment);
    }

    /**
     * Valida la firma x-signature del webhook de MP. En NO producción devuelve true
     * (tests/sandbox). En producción recalcula el HMAC:
     *   manifest = "id:{dataId};request-id:{requestId};ts:{ts};"
     *   v1 = HMAC-SHA256(manifest, webhook_secret)  (ts y v1 vienen en el header).
     */
    public function verifySignature(string $signatureHeader = '', string $requestId = '', string $dataId = ''): bool
    {
        if (! app()->isProduction()) {
            return true;
        }

        $secret = (string) config('services.mercadopago.webhook_secret', '');
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            $kv = explode('=', trim($piece), 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if (! $ts || ! $v1) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }

    public function handleWebhook(array $payload): ?Charge
    {
        [$status, $chargeId] = $this->resolveWebhook($payload);
        if (! $chargeId) {
            return null;
        }

        $charge = Charge::find($chargeId);
        if (! $charge) {
            return null;
        }

        if ($status === 'approved') {
            $payment = Payment::where('charge_id', $charge->id)
                ->where('provider', PaymentProvider::MercadoPago->value)->latest()->first()
                ?? (function () use ($charge) {
                    $mode = (string) Settings::get('payments.collection_mode', 'direct');
                    return Payment::create([
                        'charge_id'          => $charge->id,
                        'provider'           => PaymentProvider::MercadoPago->value,
                        'amount'             => $charge->amount,
                        'external_reference' => $charge->id,
                        'collection_mode'    => $mode,
                        'commission_rate'    => $this->deriveCommissionRate($charge),
                    ]);
                })();
            if ($charge->status !== ChargeStatus::Paid) {
                $this->confirm($payment);
            }
        } elseif ($status === 'rejected' && $charge->status === ChargeStatus::Pending) {
            $charge->transitionTo(ChargeStatus::Failed);
            $payment = Payment::where('charge_id', $charge->id)
                ->where('provider', PaymentProvider::MercadoPago->value)
                ->latest()->first();
            if ($payment) {
                event(new PaymentFailed($payment->fresh()->charge));
            }
        }

        return $charge;
    }

    /** @return array{0: ?string, 1: ?string} [status, chargeId] */
    protected function resolveWebhook(array $payload): array
    {
        $payloadStatus = $payload['data']['status'] ?? null;
        $payloadCharge = $payload['data']['external_reference'] ?? null;
        $paymentId     = $payload['data']['id'] ?? null;

        if ($this->isConfigured() && $paymentId && ! $payloadStatus) {
            try {
                $this->configureSdk($this->accessToken());
                $mp = (new PaymentClient)->get((int) $paymentId);

                return [$mp->status, $mp->external_reference];
            } catch (\Throwable) {
                return [null, $payloadCharge];
            }
        }

        return [$payloadStatus, $payloadCharge];
    }

    protected function accessToken(): ?string
    {
        return Settings::get('payments.mercadopago.access_token');
    }

    protected function isSplit(): bool
    {
        return Settings::get('payments.collection_mode', 'direct') === 'split';
    }

    protected function sellerAccessToken(): ?string
    {
        return Settings::get('payments.mercadopago.seller_access_token');
    }

    protected function configureSdk(?string $token): void
    {
        if ($token) {
            MercadoPagoConfig::setAccessToken($token);
        }
    }

    protected function deriveCommissionRate(Charge $charge): ?float
    {
        return $charge->application_fee !== null && $charge->amount > 0
            ? round($charge->application_fee / $charge->amount, 4)
            : null;
    }
}
