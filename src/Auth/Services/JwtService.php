<?php

namespace Innertia\Auth\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Innertia\Auth\Models\Session;
use Innertia\Facades\Settings;

/**
 * Servicio JWT propio de Innertia. Firma/verifica con firebase/php-jwt
 * (sin tymon/jwt-auth). El guard (JwtGuard), las sesiones (user_sessions)
 * y la revocación viven encima de este codec.
 */
class JwtService
{
    public function generateToken(Authenticatable $user, array $extraClaims = []): string
    {
        $now    = time();
        $ttlMin = (int) config('jwt.ttl', 60);

        $claims = array_merge([
            'sub' => (string) $user->getAuthIdentifier(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttlMin * 60),
            'jti' => bin2hex(random_bytes(16)),
        ], $extraClaims);

        $token = JWT::encode($claims, $this->secret(), $this->algo());

        $this->registerSession($user, $token);

        return $token;
    }

    /** Decodifica + valida firma/exp. Devuelve el payload (stdClass) o null. */
    public function validateToken(string $token): ?object
    {
        if ($this->isBlacklisted($token)) {
            return null;
        }

        return $this->decode($token);
    }

    public function invalidateToken(string $token): void
    {
        $ttl = (int) config('jwt.ttl', 60) * 60;
        Cache::put($this->blacklistKey($token), true, $ttl);

        Session::where('token_hash', $this->hash($token))->delete();
    }

    public function refreshToken(string $token): string
    {
        $payload = $this->validateToken($token);

        if (! $payload || ! $this->sessionIsActive($token)) {
            throw new \RuntimeException('Token inválido o sesión revocada.');
        }

        $model = config('auth.providers.users.model');
        $user  = $model::find($payload->sub ?? null);

        if (! $user) {
            throw new \RuntimeException('Usuario no encontrado para refresh.');
        }

        $this->invalidateToken($token);

        // Preservar claims de dominio (ej. context) en el token nuevo.
        $extra = [];
        foreach (['context'] as $claim) {
            if (isset($payload->{$claim})) {
                $extra[$claim] = $payload->{$claim};
            }
        }

        return $this->generateToken($user, $extra);
    }

    public function getUserFromToken(string $token): ?Authenticatable
    {
        $payload = $this->validateToken($token);

        if (! $payload) {
            return null;
        }

        // La sesión debe seguir activa en DB. Revocar = borrar la fila
        // (admin, sesión única, logout) invalida el token de inmediato,
        // aunque el JWT siga siendo criptográficamente válido.
        if (! $this->sessionIsActive($token)) {
            return null;
        }

        $model = config('auth.providers.users.model');

        return $model::find($payload->sub ?? null);
    }

    /** Decodifica el token con la clave/algoritmo configurados. Null si inválido/expirado. */
    public function decode(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret(), $this->algo()));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Verifica que exista una fila de sesión activa para este token.
     * token_hash = sha256(jwt) — el mismo hash que guarda registerSession().
     */
    protected function sessionIsActive(string $token): bool
    {
        return Session::where('token_hash', $this->hash($token))
            ->where('expires_at', '>', now())
            ->exists();
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
        return \Innertia\Facades\Innertia::tenant()?->getKey();
    }

    protected function secret(): string
    {
        $secret = config('jwt.secret');

        if (empty($secret)) {
            throw new \RuntimeException('JWT secret no configurado. Ejecuta `php artisan jwt:secret`.');
        }

        return $secret;
    }

    protected function algo(): string
    {
        return config('jwt.algo', 'HS256');
    }
}
