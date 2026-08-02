<?php

namespace Innertia\Payments;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Innertia\Facades\{Innertia, Settings};
use RuntimeException;

class MercadoPagoOAuth
{
    private const TOKEN_ENDPOINT = 'https://api.mercadopago.com/oauth/token';
    private const AUTHORIZE_URL  = 'https://auth.mercadopago.cl/authorization';

    public function stateFor(string $tenantKey): string
    {
        return Crypt::encryptString($tenantKey);
    }

    public function tenantFromState(string $state): string
    {
        try {
            return Crypt::decryptString($state);
        } catch (\Throwable) {
            throw new RuntimeException('State de OAuth inválido.');
        }
    }

    public function authorizeUrl(string $tenantKey): string
    {
        $query = http_build_query([
            'client_id'     => config('services.mercadopago.app_id'),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'redirect_uri'  => config('services.mercadopago.redirect_uri'),
            'state'         => $this->stateFor($tenantKey),
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    public function handleCallback(string $code, string $state): void
    {
        $tenantKey = $this->tenantFromState($state);

        $tokens = $this->exchange([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => config('services.mercadopago.redirect_uri'),
        ]);

        $this->storeTokens($tenantKey, $tokens);
    }

    public function refresh(string $tenantKey): void
    {
        $wasActive = Innertia::tenant()?->key === $tenantKey;
        if (! $wasActive) {
            Innertia::activate($tenantKey);
        }

        try {
            $refreshToken = Settings::get('payments.mercadopago.seller_refresh_token');
            if (! $refreshToken) {
                return;
            }

            $tokens = $this->exchange([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

            $this->storeTokens($tenantKey, $tokens);
        } finally {
            if (! $wasActive) {
                Innertia::deactivate();
            }
        }
    }

    private function exchange(array $params): array
    {
        $response = Http::asForm()->post(self::TOKEN_ENDPOINT, array_merge([
            'client_id'     => config('services.mercadopago.app_id'),
            'client_secret' => config('services.mercadopago.client_secret'),
        ], $params));

        if ($response->failed()) {
            throw new RuntimeException('MercadoPago OAuth: intercambio de token falló.');
        }

        return $response->json();
    }

    private function storeTokens(string $tenantKey, array $tokens): void
    {
        $wasActive = Innertia::tenant()?->key === $tenantKey;
        if (! $wasActive) {
            Innertia::activate($tenantKey);
        }

        try {
            if (! empty($tokens['access_token'])) {
                Settings::set('payments.mercadopago.seller_access_token', $tokens['access_token'], 'string', true);
            }
            if (! empty($tokens['refresh_token'])) {
                Settings::set('payments.mercadopago.seller_refresh_token', $tokens['refresh_token'], 'string', true);
            }
            if (! empty($tokens['user_id'])) {
                Settings::set('payments.mercadopago.seller_user_id', (string) $tokens['user_id'], 'string');
            }
            if (! empty($tokens['expires_in'])) {
                Settings::set(
                    'payments.mercadopago.seller_token_expires_at',
                    now()->addSeconds((int) $tokens['expires_in'])->toIso8601String(),
                    'string',
                );
            }
        } finally {
            if (! $wasActive) {
                Innertia::deactivate();
            }
        }
    }
}
