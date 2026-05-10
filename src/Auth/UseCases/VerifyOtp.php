<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Platform\Contracts\UseCase;

class VerifyOtp extends UseCase
{
    public function __construct(
        protected OtpService $otp,
        protected JwtService $jwt,
    ) {}

    public function execute(Authenticatable $user, string $code, string $action): array
    {
        if (! $this->otp->verify($user, $code, $action)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'Invalid or expired OTP.'], 422)
            );
        }

        // If 2FA is enabled, require second factor before issuing token
        if (config('innertia.auth.2fa.enabled', false) && method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            return ['requires_2fa' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        $token = $this->jwt->generateToken($user);

        return ['token' => $token, 'user' => $user];
    }
}
