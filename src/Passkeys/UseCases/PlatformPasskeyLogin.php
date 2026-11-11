<?php

namespace Innertia\Passkeys\UseCases;

use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Platform\Contracts\UseCase;

class PlatformPasskeyLogin extends UseCase
{
    public function __construct(
        public readonly string $key,
        public readonly array $response,
    ) {}

    public function execute(): array
    {
        $user = (new VerifyPasskeyAssertion($this->key, $this->response))->execute();

        if (! $user->is_platform_admin) {
            throw new ForbiddenException('Esta cuenta no tiene acceso al panel de plataforma.');
        }

        // El cambio de clave obligatorio de la cuenta de plataforma lo sigue
        // imponiendo Authenticate (solo deja pasar platform/auth/password).
        return [
            'token' => app(JwtService::class)->generateToken($user, ['platform' => true]),
            'user'  => $user,
        ];
    }
}
