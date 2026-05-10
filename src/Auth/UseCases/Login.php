<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\Hash;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Facades\Settings;
use Innertia\Platform\Contracts\UseCase;

class Login extends UseCase
{
    public function __construct(
        protected JwtService $jwt,
        protected OtpService $otp,
    ) {}

    public function execute(string $email, string $password): array
    {
        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'Invalid credentials.'], 422)
            );
        }

        // OTP required before issuing token
        if (Settings::get('auth.otp.enabled', config('innertia.auth.otp.enabled', false))) {
            $this->otp->send($user, 'login');

            return ['requires_otp' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        $token = $this->jwt->generateToken($user);

        return ['token' => $token, 'user' => $user];
    }
}
