<?php

namespace Innertia\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Innertia\Facades\Settings;
use Innertia\Models\Session;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Facades\JWTFactory;

class JwtService
{
    public function generateToken(Authenticatable $user, array $extraClaims = []): string
    {
        $claims = array_merge(['sub' => $user->getAuthIdentifier()], $extraClaims);
        $payload = JWTFactory::customClaims($claims)->make();
        $token = JWTAuth::encode($payload)->get();

        $this->registerSession($user, $token);

        return $token;
    }

    public function validateToken(string $token): ?object
    {
        if ($this->isBlacklisted($token)) {
            return null;
        }

        try {
            return JWTAuth::setToken($token)->getPayload();
        } catch (\Exception) {
            return null;
        }
    }

    public function invalidateToken(string $token): void
    {
        $ttl = config('jwt.ttl', 60) * 60;
        Cache::put($this->blacklistKey($token), true, $ttl);

        Session::where('token_hash', $this->hash($token))->delete();
    }

    public function refreshToken(string $token): string
    {
        $this->invalidateToken($token);

        try {
            $new = JWTAuth::setToken($token)->refresh();
            return $new->get();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getUserFromToken(string $token): ?Authenticatable
    {
        $payload = $this->validateToken($token);

        if (! $payload) {
            return null;
        }

        $model = config('auth.providers.users.model');

        return $model::find($payload->get('sub'));
    }

    protected function registerSession(Authenticatable $user, string $token): void
    {
        $request = request();
        $ttl     = now()->addMinutes(config('jwt.ttl', 60));

        $isSaas = config('innertia.mode') === 'saas';

        $sessionData = [
            'user_id'    => $user->getAuthIdentifier(),
            'token_hash' => $this->hash($token),
            'device_id'  => $request->header('X-Device-Id'),
            'ip'         => $request->ip(),
            'browser'    => $request->userAgent(),
            'expires_at' => $ttl,
        ];

        if ($isSaas) {
            $sessionData['tenant_id'] = $this->resolveTenantId();
        }

        Session::create($sessionData);

        if (Settings::get('auth.sessions.restrict_concurrent', config('innertia.auth.sessions.restrict_concurrent', false))) {
            $query = Session::where('user_id', $user->getAuthIdentifier())
                ->where('token_hash', '!=', $this->hash($token));

            if ($isSaas) {
                $query->where('tenant_id', $this->resolveTenantId());
            }

            $query->delete();
        }
    }

    protected function isBlacklisted(string $token): bool
    {
        return Cache::has($this->blacklistKey($token));
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function blacklistKey(string $token): string
    {
        return 'jwt_blacklist_' . $this->hash($token);
    }

    protected function resolveTenantId(): mixed
    {
        return config('innertia.mode') === 'saas' && function_exists('tenant')
            ? tenant('id')
            : null;
    }
}
