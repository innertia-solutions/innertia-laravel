<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\Hash;
use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\UnprocessableException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Used for invitation / first-login flows after OTP verification.
 * The user_id was returned by VerifyOtp { requires_password_set: true, user_id }.
 */
class SetPassword extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly string $password,
        public readonly string $passwordConfirmation,
        public readonly string $context,
    ) {

    }

    public function execute(): array
    {
        if ($this->password !== $this->passwordConfirmation) {
            throw new UnprocessableException(errors: ['password_confirmation' => ['Passwords do not match.']]);
        }

        $model = config('auth.providers.users.model');
        $user  = $model::find($this->userId);

        // Endpoint pre-auth (sin sesión): solo para cuentas que nunca se
        // activaron. Una cuenta activa no puede re-fijar su contraseña por aquí
        // (antes: toma de cuenta con solo conocer el user_id).
        if (! $user || $user->email_verified_at !== null) {
            throw new UnprocessableException(errors: ['user_id' => ['Esta cuenta ya fue activada. Usa "¿Olvidaste tu contraseña?".']]);
        }

        $user->update([
            'password'          => Hash::make($this->password),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $token = app(JwtService::class)->generateToken($user, ['context' => $this->context]);

        return ['token' => $token, 'user' => $user];
    }
}
