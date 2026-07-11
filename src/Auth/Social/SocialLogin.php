<?php

namespace Innertia\Auth\Social;

use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;
use Laravel\Socialite\Contracts\User as SocialUser;

class SocialLogin extends UseCase
{
    /**
     * @param  array<string,mixed>  $state  claims del state OAuth (incluye 'context' y opcionalmente 'join_token')
     */
    public function __construct(
        public readonly SocialProvider $provider,
        public readonly SocialUser     $socialUser,
        public readonly array          $state,
    ) {}

    public function execute(): array
    {
        $context = $this->state['context'] ?? null;
        if (! $context) {
            throw new NotFoundException('Missing context.');
        }

        // 1 — Buscar el usuario local por email (identidad canónica).
        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $this->socialUser->getEmail())->first();

        // 2 — Si no existe, delegar en el provisioner (default: throw NotFound = login-only).
        $created = false;
        if (! $user) {
            $user = app(SocialProvisioner::class)->provision($this->provider, $this->socialUser, $this->state);
            $created = true;
        }

        // 3 — El contexto debe existir en config.
        if (! array_key_exists($context, config('innertia.contexts', []))) {
            throw new NotFoundException("Context '{$context}' not found.");
        }

        // 4 — Un usuario ya existente debe tener acceso al contexto (los recién provisionados lo obtienen al crearse).
        if (! $created && method_exists($user, 'hasContext') && ! $user->hasContext($context)) {
            throw new ForbiddenException("Access to '{$context}' is not allowed for this user.");
        }

        // 5 — Emitir JWT con claims provider + context.
        $token = app(JwtService::class)->generateToken($user, [
            'context'  => $context,
            'provider' => $this->provider->value,
        ]);

        return ['token' => $token, 'user' => $user, 'created' => $created];
    }
}
