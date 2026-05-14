<?php

namespace Innertia\Auth\UseCases;

use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Facades\Settings;
use Innertia\Platform\Contracts\UseCase;

class VerifyOtp extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly string $code,
        public readonly string $action,
        public readonly string $app,
    ) {
       
    }

    public function execute(): array
    {
        $model = config('auth.providers.users.model');
        $user  = $model::findOrFail($this->userId);

        if (! app(OtpService::class)->verify($user, $this->code, $this->action)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'Invalid or expired OTP.'], 422)
            );
        }

        // force_password_change OTP → user must set new password before getting a token
        if ($user->force_password_change) {
            return ['requires_password_change' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        if (
            Settings::get('auth.2fa.enabled', config('innertia.auth.2fa.enabled', false))
            && $user->two_factor_enabled
        ) {
            return ['requires_2fa' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        $token = app(JwtService::class)->generateToken($user, ['app' => $this->app]);

        return ['token' => $token, 'user' => $user];
    }
}
