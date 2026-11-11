<?php

namespace Innertia\Passkeys\UseCases;

use Illuminate\Support\Facades\DB;
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

        // Lock de fila: evita la pérdida de actualización del contador entre ingresos concurrentes.
        return DB::transaction(function () use ($credentialId, $challenge) {
            $passkey = Passkey::where('credential_id', $credentialId)->lockForUpdate()->first();
            if (! $passkey) {
                PasskeyLog::warning('passkey.login_failed', ['reason' => 'credencial desconocida']);
                throw $this->logged(new PasskeyVerificationFailed('credencial desconocida'));
            }

            try {
                $updated = Webauthn::verifyAssertion($passkey->credential, $challenge['options'], $this->response);
            } catch (PasskeyVerificationFailed $e) {
                $reason = str_contains(strtolower($e->getMessage()), 'counter') ? 'counter_regression' : $e->getMessage();
                PasskeyLog::warning('passkey.login_failed', ['passkey_id' => $passkey->id, 'user_id' => $passkey->user_id, 'reason' => $reason]);
                throw $this->logged($e);
            }

            $model = config('auth.providers.users.model');
            $user = $model::find($passkey->user_id);
            if (! $user) {
                PasskeyLog::warning('passkey.login_failed', ['passkey_id' => $passkey->id, 'user_id' => $passkey->user_id, 'reason' => 'usuario inexistente']);
                throw $this->logged(new PasskeyVerificationFailed('usuario inexistente'));
            }

            $passkey->forceFill(['credential' => $updated, 'last_used_at' => now()])->save();

            PasskeyLog::info('passkey.login', ['passkey_id' => $passkey->id, 'user_id' => $passkey->user_id]);

            return $user;
        });
    }

    private function logged(PasskeyVerificationFailed $e): PasskeyVerificationFailed
    {
        $e->logged = true;

        return $e;
    }
}
