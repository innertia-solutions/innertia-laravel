<?php

namespace Innertia\Passkeys\UseCases;

use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\Support\ChallengeStore;
use Innertia\Passkeys\Support\PasskeyLog;
use Innertia\Passkeys\Support\Webauthn;
use Innertia\Platform\Contracts\UseCase;

/** Valida la assertion y devuelve el usuario dueño de la passkey. */
class VerifyPasskeyAssertion extends UseCase
{
    public function __construct(
        public readonly string $key,
        public readonly array $response,
    ) {}

    public function execute(): mixed
    {
        $challenge = ChallengeStore::pull($this->key, 'login');
        $credentialId = Webauthn::credentialIdOf($this->response);

        $passkey = Passkey::where('credential_id', $credentialId)->first();
        if (! $passkey) {
            PasskeyLog::warning('passkey.login_failed', ['reason' => 'credencial desconocida']);
            throw new PasskeyVerificationFailed('credencial desconocida');
        }

        try {
            $updated = Webauthn::verifyAssertion($passkey->credential, $challenge['options'], $this->response);
        } catch (PasskeyVerificationFailed $e) {
            $reason = str_contains(strtolower($e->getMessage()), 'counter') ? 'counter_regression' : $e->getMessage();
            PasskeyLog::warning('passkey.login_failed', ['passkey_id' => $passkey->id, 'user_id' => $passkey->user_id, 'reason' => $reason]);
            throw $e;
        }

        $passkey->forceFill(['credential' => $updated, 'last_used_at' => now()])->save();

        $model = config('auth.providers.users.model');
        $user = $model::find($passkey->user_id);
        if (! $user) {
            throw new PasskeyVerificationFailed('usuario inexistente');
        }

        PasskeyLog::info('passkey.login', ['passkey_id' => $passkey->id, 'user_id' => $passkey->user_id]);

        return $user;
    }
}
