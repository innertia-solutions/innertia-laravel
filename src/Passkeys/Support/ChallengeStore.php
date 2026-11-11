<?php

namespace Innertia\Passkeys\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;

/** Challenges de un solo uso (no dependen de la sesión: el stack usa JWT). */
final class ChallengeStore
{
    public static function put(string $type, array $data): string
    {
        $key = Str::random(40);
        Cache::put(self::cacheKey($key), ['type' => $type] + $data, PasskeyConfig::challengeTtl());

        return $key;
    }

    /** Lee y borra. Vencido, inexistente o de otro tipo → PasskeyVerificationFailed. */
    public static function pull(string $key, string $type): array
    {
        $data = Cache::pull(self::cacheKey($key));

        if (! is_array($data) || ($data['type'] ?? null) !== $type) {
            throw new PasskeyVerificationFailed('challenge inválido o vencido');
        }

        return $data;
    }

    private static function cacheKey(string $key): string
    {
        return 'passkeys:challenge:' . $key;
    }
}
