<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Innertia\Auth\Mailables\PasswordChangedMail;
use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\UnprocessableException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Used when force_password_change = true. User provides current OTP + new password.
 */
class ChangePassword extends UseCase
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

        // Endpoint pre-auth (sin sesión): solo vale para el cambio FORZADO que
        // exige el login. Sin este guard, cualquiera con un user_id podía fijar
        // la contraseña de otra cuenta y recibir su JWT (toma de cuenta).
        // Mismo error para "no existe" y "no aplica" (sin enumeración).
        if (! $user || ! $user->force_password_change) {
            throw new UnprocessableException(errors: ['user_id' => ['No hay un cambio de contraseña pendiente para esta cuenta.']]);
        }

        $user->update([
            'password'              => Hash::make($this->password),
            'force_password_change' => false,
        ]);

        Mail::to($user->email)->queue(new PasswordChangedMail($user));

        $token = app(JwtService::class)->generateToken($user, ['context' => $this->context]);

        return ['token' => $token, 'user' => $user];
    }
}
