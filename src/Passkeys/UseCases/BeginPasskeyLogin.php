<?php

namespace Innertia\Passkeys\UseCases;

use Innertia\Passkeys\Support\ChallengeStore;
use Innertia\Passkeys\Support\Webauthn;
use Innertia\Platform\Contracts\UseCase;

/** Opciones sin usuario: sirve para el botón y para el autocompletado (conditional UI). */
class BeginPasskeyLogin extends UseCase
{
    /** @return array{key: string, options: array} */
    public function execute(): array
    {
        $options = Webauthn::requestOptions();

        return ['key' => ChallengeStore::put('login', ['options' => $options]), 'options' => $options];
    }
}
