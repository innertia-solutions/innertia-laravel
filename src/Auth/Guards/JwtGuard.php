<?php

namespace Innertia\Auth\Guards;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Innertia\Auth\Services\JwtService;

class JwtGuard implements Guard
{
    use GuardHelpers;

    protected ?string $token = null;

    public function __construct(
        UserProvider $provider,
        protected Request $request,
        protected JwtService $jwt,
    ) {
        $this->provider = $provider;
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->getToken();

        if (! $token) {
            return null;
        }

        $this->user = $this->jwt->getUserFromToken($token);

        return $this->user;
    }

    public function validate(array $credentials = []): bool
    {
        return (bool) $this->provider->retrieveByCredentials($credentials);
    }

    public function attempt(array $credentials): ?string
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if (! $user || ! $this->provider->validateCredentials($user, $credentials)) {
            return null;
        }

        return $this->login($user);
    }

    public function login(Authenticatable $user): string
    {
        $this->setUser($user);
        return $this->jwt->generateToken($user);
    }

    public function logout(): void
    {
        if ($token = $this->getToken()) {
            $this->jwt->invalidateToken($token);
        }
        $this->user = null;
        $this->token = null;
    }

    public function refresh(): string
    {
        $token = $this->getToken();

        if (! $token) {
            throw new \RuntimeException('No token to refresh.');
        }

        return $this->jwt->refreshToken($token);
    }

    public function getToken(): ?string
    {
        if ($this->token) {
            return $this->token;
        }

        $header = $this->request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $this->token = substr($header, 7);
        }

        return $this->token;
    }

    public function id(): mixed
    {
        return $this->user()?->getAuthIdentifier();
    }
}
