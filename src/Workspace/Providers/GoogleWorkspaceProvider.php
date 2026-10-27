<?php

namespace Innertia\Workspace\Providers;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Innertia\Facades\Settings;
use Innertia\Workspace\Contracts\WorkspaceProvider;
use Innertia\Workspace\WorkspaceException;

class GoogleWorkspaceProvider implements WorkspaceProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/admin.directory.user';

    private const BASE = 'https://admin.googleapis.com/admin/directory/v1';

    public function configured(): bool
    {
        return filled(Settings::get('workspace.google.service_account_json'))
            && filled(Settings::get('workspace.google.admin_email'));
    }

    public function validate(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Faltan el JSON de la service account o el email de admin.'];
        }
        try {
            $this->listUsers(max: 1);

            return ['ok' => true, 'message' => 'Conexión y delegación OK.', 'details' => ['Se pudo listar usuarios del Workspace.']];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function listUsers(?string $query = null, ?string $pageToken = null, int $max = 50): array
    {
        $res = $this->http()->get(self::BASE.'/users', array_filter([
            'customer'   => 'my_customer',
            'maxResults' => $max,
            'orderBy'    => 'email',
            'query'      => $query,
            'pageToken'  => $pageToken,
        ], fn ($v) => $v !== null && $v !== ''));

        $this->ok($res);

        return [
            'users'           => array_map(fn ($u) => $this->mapUser($u), $res->json('users', [])),
            'next_page_token' => $res->json('nextPageToken'),
        ];
    }

    public function createUser(string $email, string $givenName, string $familyName, string $password, bool $changeNext = true): array
    {
        $res = $this->http()->post(self::BASE.'/users', [
            'primaryEmail' => $email,
            'name'         => ['givenName' => $givenName, 'familyName' => $familyName],
            'password'     => $password,
            'changePasswordAtNextLogin' => $changeNext,
        ]);
        $this->ok($res);

        return $this->mapUser($res->json());
    }

    public function resetPassword(string $userKey, string $password, bool $changeNext = true): array
    {
        $res = $this->http()->patch(self::BASE.'/users/'.rawurlencode($userKey), [
            'password' => $password,
            'changePasswordAtNextLogin' => $changeNext,
        ]);
        $this->ok($res);

        return $this->mapUser($res->json());
    }

    public function setSuspended(string $userKey, bool $suspended): array
    {
        $res = $this->http()->patch(self::BASE.'/users/'.rawurlencode($userKey), ['suspended' => $suspended]);
        $this->ok($res);

        return $this->mapUser($res->json());
    }

    public function deleteUser(string $userKey): void
    {
        $this->ok($this->http()->delete(self::BASE.'/users/'.rawurlencode($userKey)));
    }

    private function http()
    {
        return Http::withToken($this->token())->acceptJson()->timeout(20);
    }

    private function ok($res): void
    {
        if ($res->failed()) {
            throw new WorkspaceException($res->json('error.message') ?? ('Error de Google (HTTP '.$res->status().').'));
        }
    }

    private function serviceAccount(): array
    {
        $json = Settings::get('workspace.google.service_account_json');
        $sa = json_decode((string) $json, true);
        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new WorkspaceException('El JSON de la service account es inválido.');
        }

        return $sa;
    }

    private function token(): string
    {
        $admin = (string) Settings::get('workspace.google.admin_email');

        return Cache::remember('workspace.google.token.'.md5($admin), 3300, function () use ($admin) {
            $sa = $this->serviceAccount();
            $now = time();
            $assertion = JWT::encode([
                'iss'   => $sa['client_email'],
                'sub'   => $admin,
                'scope' => self::SCOPE,
                'aud'   => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], $sa['private_key'], 'RS256');

            $res = Http::asForm()->timeout(20)->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]);

            if ($res->failed() || ! $res->json('access_token')) {
                throw new WorkspaceException($res->json('error_description') ?? $res->json('error') ?? 'No se pudo autenticar con Google.');
            }

            return $res->json('access_token');
        });
    }

    private function mapUser(array $u): array
    {
        return [
            'id'            => $u['id'] ?? null,
            'email'         => $u['primaryEmail'] ?? null,
            'full_name'     => $u['name']['fullName'] ?? trim(($u['name']['givenName'] ?? '').' '.($u['name']['familyName'] ?? '')),
            'given_name'    => $u['name']['givenName'] ?? null,
            'family_name'   => $u['name']['familyName'] ?? null,
            'suspended'     => (bool) ($u['suspended'] ?? false),
            'last_login_at' => $u['lastLoginTime'] ?? null,
            'created_at'    => $u['creationTime'] ?? null,
            'is_admin'      => (bool) ($u['isAdmin'] ?? false),
        ];
    }
}
