<?php

namespace Innertia\Passkeys\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;
use Innertia\Passkeys\Exceptions\PasskeyVerificationFailed;
use Innertia\Passkeys\Mail\PasskeyAddedMail;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\Support\ChallengeStore;
use Innertia\Passkeys\Support\PasskeyLog;
use Innertia\Passkeys\Support\Webauthn;
use Innertia\Platform\Contracts\UseCase;

class FinishPasskeyRegistration extends UseCase
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $key,
        public readonly array $response,
        public readonly ?string $name = null,
    ) {}

    public function execute(): Passkey
    {
        $userId = (string) $this->user->getAuthIdentifier();
        $challenge = ChallengeStore::pull($this->key, 'register');

        if (($challenge['user_id'] ?? null) !== $userId) {
            PasskeyLog::warning('passkey.register_failed', ['user_id' => $userId, 'reason' => 'challenge de otro usuario']);
            $e = new PasskeyVerificationFailed('challenge de otro usuario');
            $e->logged = true;
            throw $e;
        }

        try {
            $verified = Webauthn::verifyRegistration($challenge['options'], $this->response);
        } catch (PasskeyVerificationFailed $e) {
            PasskeyLog::warning('passkey.register_failed', ['user_id' => $userId, 'reason' => $e->getMessage()]);
            $e->logged = true;
            throw $e;
        }

        $name = trim((string) $this->name);

        $passkey = Passkey::create([
            'user_id'       => $userId,
            'name'          => $name !== '' ? mb_substr($name, 0, 120) : 'Passkey',
            'credential_id' => $verified['credential_id'],
            'credential'    => $verified['credential'],
            'aaguid'        => $verified['aaguid'],
        ]);

        PasskeyLog::info('passkey.registered', ['user_id' => $userId, 'passkey_id' => $passkey->id]);

        if (! empty($this->user->email)) {
            // El correo es solo un aviso: un fallo de la cola no debe romper el registro.
            try {
                Mail::to($this->user->email)->queue(new PasskeyAddedMail($this->user, $passkey->name));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $passkey;
    }
}
