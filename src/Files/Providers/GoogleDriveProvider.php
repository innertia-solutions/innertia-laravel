<?php

namespace Innertia\Files\Providers;

use Innertia\Auth\Models\User;
use Laravel\Socialite\Facades\Socialite;

class GoogleDriveProvider implements FileProvider
{
    public function key(): string
    {
        return 'google_drive';
    }

    private function cfg(string $key): mixed
    {
        return config("innertia.files.providers.google_drive.{$key}");
    }

    /**
     * Mirror ConfigureSocialite: wire services.google before Socialite reads it.
     */
    private function configureSocialite(): void
    {
        config([
            'services.google.client_id'     => $this->cfg('client_id'),
            'services.google.client_secret' => $this->cfg('client_secret'),
            'services.google.redirect'      => rtrim((string) config('app.url'), '/')
                . '/api/files/providers/google_drive/callback',
        ]);
    }

    public function connectRedirectUrl(string $scope, ?string $tenantId): string
    {
        $this->configureSocialite();

        $state = base64_encode(json_encode(['scope' => $scope, 'tenant' => $tenantId]));

        return Socialite::driver('google')
            ->scopes($this->cfg('scopes'))
            ->with([
                'access_type' => 'offline',
                'prompt'      => 'consent',
                'state'       => $state,
            ])
            ->stateless()
            ->redirect()
            ->getTargetUrl();
    }

    public function handleCallback(array $callback, string $scope, ?string $tenantId): ProviderConnection
    {
        $this->configureSocialite();

        $g = Socialite::driver('google')->stateless()->user();

        return ProviderConnection::updateOrCreate(
            [
                'provider'  => 'google_drive',
                'scope'     => $scope,
                'user_id'   => $scope === 'user' ? auth()->id() : null,
                'tenant_id' => $tenantId,
            ],
            [
                'email'         => $g->getEmail(),
                'access_token'  => $g->token,
                'refresh_token' => $g->refreshToken,
                'expires_at'    => isset($g->expiresIn) ? now()->addSeconds($g->expiresIn) : null,
            ]
        );
    }

    public function disconnect(ProviderConnection $conn): void
    {
        $conn->delete();
    }

    public function connectionFor(string $scope, ?User $user, ?string $tenantId): ?ProviderConnection
    {
        $query = ProviderConnection::query()
            ->where('provider', 'google_drive')
            ->where('scope', $scope);

        if ($scope === 'user' && $user) {
            $query->where('user_id', $user->id);
        }

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }

    public function pickerConfig(ProviderConnection $conn): array
    {
        return [
            'api_key' => $this->cfg('api_key'),
            'app_id'  => explode('-', (string) $this->cfg('client_id'))[0],
            'token'   => $conn->access_token,
            'scopes'  => $this->cfg('scopes'),
        ];
    }

    public function ensureAppFolder(ProviderConnection $conn): string
    {
        throw new \BadMethodCallException('Fase 2');
    }

    public function upload(ProviderConnection $conn, string $folderId, string $name, string $bytes, string $mime): array
    {
        throw new \BadMethodCallException('Fase 2');
    }

    public function download(ProviderConnection $conn, string $externalId): string
    {
        throw new \BadMethodCallException('Fase 2');
    }

    public function metadata(ProviderConnection $conn, string $externalId): array
    {
        throw new \BadMethodCallException('Fase 2');
    }

    public function share(ProviderConnection $conn, string $externalId, array $emails): void
    {
        throw new \BadMethodCallException('Fase 2');
    }
}
