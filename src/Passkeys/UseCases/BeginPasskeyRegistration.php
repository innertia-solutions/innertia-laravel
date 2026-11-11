<?php

namespace Innertia\Passkeys\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\Support\ChallengeStore;
use Innertia\Passkeys\Support\Webauthn;
use Innertia\Platform\Contracts\UseCase;

class BeginPasskeyRegistration extends UseCase
{
    public function __construct(public readonly Authenticatable $user) {}

    /** @return array{key: string, options: array} */
    public function execute(): array
    {
        $userId = (string) $this->user->getAuthIdentifier();
        $exclude = Passkey::where('user_id', $userId)->pluck('credential_id')
            ->map(fn (string $id) => base64_decode(strtr($id, '-_', '+/') . str_repeat('=', (4 - strlen($id) % 4) % 4)))
            ->all();

        $options = Webauthn::creationOptions(
            $userId,
            (string) $this->user->email,
            (string) ($this->user->name ?? $this->user->email),
            $exclude,
        );

        $key = ChallengeStore::put('register', ['user_id' => $userId, 'options' => $options]);

        return ['key' => $key, 'options' => $options];
    }
}
