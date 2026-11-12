<?php

namespace Innertia\Auth\Social;

use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Exceptions\PlatformAccountException;
use Innertia\Platform\Contracts\UseCase;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Login con un proveedor OAuth (Google, Microsoft, GitHub).
 *
 * Dos modos:
 *  - Contexto fijo: `$state['context']` (comportamiento histórico). El usuario debe
 *    tener ese contexto o se lanza ForbiddenException.
 *  - Probing (`$contexts` no nulo): igual que el login único con contraseña/passkey,
 *    se emite el token para el PRIMER contexto de la lista que el usuario posea
 *    (los desconocidos en config se ignoran). Sin ninguno → ForbiddenException.
 *    El provisioner (registro social) solo aplica en modo contexto fijo: en probing
 *    un correo no registrado siempre es NotFoundException (no se crean cuentas).
 *
 * Con `innertia.platform.separate_identity` las cuentas de plataforma se rechazan
 * (PlatformAccountException), igual que Login::issueFor y PasskeyLogin.
 * El resultado incluye `context` (el contexto con el que se emitió el token).
 */
class SocialLogin extends UseCase
{
    /**
     * @param  array<string,mixed>  $state     claims del state OAuth (incluye 'context' y opcionalmente 'join_token')
     * @param  list<string>|null    $contexts  orden de preferencia para el probing; null = contexto fijo del state
     */
    public function __construct(
        public readonly SocialProvider $provider,
        public readonly SocialUser     $socialUser,
        public readonly array          $state,
        public readonly ?array         $contexts = null,
    ) {}

    public function execute(): array
    {
        if ($this->contexts !== null) {
            return $this->executeProbing();
        }

        $context = $this->state['context'] ?? null;
        if (! $context) {
            throw new NotFoundException('Missing context.');
        }

        // 1 — Buscar el usuario local por email (identidad canónica).
        $user = $this->findUser();

        // 2 — Si no existe, delegar en el provisioner (default: throw NotFound = login-only).
        $created = false;
        if (! $user) {
            $user = app(SocialProvisioner::class)->provision($this->provider, $this->socialUser, $this->state);
            $created = true;
        }

        $this->rejectPlatformAccount($user);

        // 3 — El contexto debe existir en config.
        if (! array_key_exists($context, config('innertia.contexts', []))) {
            throw new NotFoundException("Context '{$context}' not found.");
        }

        // 4 — Un usuario ya existente debe tener acceso al contexto (los recién provisionados lo obtienen al crearse).
        if (! $created && method_exists($user, 'hasContext') && ! $user->hasContext($context)) {
            throw new ForbiddenException("Access to '{$context}' is not allowed for this user.");
        }

        return $this->issue($user, $context, $created);
    }

    private function executeProbing(): array
    {
        $user = $this->findUser();
        if (! $user) {
            throw new NotFoundException('Account not registered. Please contact your administrator.');
        }

        $this->rejectPlatformAccount($user);

        $known = config('innertia.contexts', []);
        foreach ($this->contexts as $context) {
            if (! is_string($context) || ! array_key_exists($context, $known)) {
                continue;
            }
            if (method_exists($user, 'hasContext') && ! $user->hasContext($context)) {
                continue;
            }

            return $this->issue($user, $context, false);
        }

        throw new ForbiddenException('This account has no access to any of the requested contexts.');
    }

    private function findUser(): mixed
    {
        $email = $this->socialUser->getEmail();
        if (! $email) {
            return null;
        }

        $model = config('auth.providers.users.model');

        return $model::where('email', $email)->first();
    }

    private function rejectPlatformAccount($user): void
    {
        if (config('innertia.platform.separate_identity') && ($user->is_platform_admin ?? false)) {
            throw new PlatformAccountException();
        }
    }

    private function issue($user, string $context, bool $created): array
    {
        // Emitir JWT con claims provider + context.
        $token = app(JwtService::class)->generateToken($user, [
            'context'  => $context,
            'provider' => $this->provider->value,
        ]);

        return ['token' => $token, 'user' => $user, 'created' => $created, 'context' => $context];
    }
}
