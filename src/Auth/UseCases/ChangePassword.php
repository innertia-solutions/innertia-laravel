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
        public readonly string $app,
    ) {
       
    }

    public function execute(): array
    {
        if ($this->password !== $this->passwordConfirmation) {
            throw new UnprocessableException(errors: ['password_confirmation' => ['Passwords do not match.']]);
        }

        $model = config('auth.providers.users.model');
        $user  = $model::findOrFail($this->userId);

        $user->update([
            'password'              => Hash::make($this->password),
            'force_password_change' => false,
        ]);

        Mail::to($user->email)->queue(new PasswordChangedMail($user));

        $token = app(JwtService::class)->generateToken($user, ['app' => $this->app]);

        return ['token' => $token, 'user' => $user];
    }
}
