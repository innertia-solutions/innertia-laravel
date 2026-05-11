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
        public readonly string $app,
    ) {}

    public function execute(): array
    {
        if ($this->password !== $this->passwordConfirmation) {
            throw new UnprocessableException(errors: ['password_confirmation' => ['Passwords do not match.']]);
        }

        $model = config('auth.providers.users.model');
        $user  = $model::findOrFail($this->userId);

        $user->update([
            'password'          => Hash::make($this->password),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $token = app(JwtService::class)->generateToken($user, ['app' => $this->app]);

        return ['token' => $token, 'user' => $user];
    }
}
