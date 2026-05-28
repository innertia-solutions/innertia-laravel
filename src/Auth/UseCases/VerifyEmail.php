<?php

namespace Innertia\Auth\UseCases;

use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Settings;
use Innertia\Platform\Contracts\UseCase;

class VerifyEmail extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly string $context,
    ) {

    }

    public function execute(): array
    {
        $model = config('auth.providers.users.model');
        $user  = $model::findOrFail($this->userId);

        if (! $user->email_verified_at) {
            $user->update(['email_verified_at' => now()]);
        }

        // After verification, proceed to OTP or 2FA if enabled
        if (Settings::get('auth.otp.enabled', config('innertia.auth.otp.enabled', false))) {
            app(\Innertia\Auth\Services\OtpService::class)->send($user, 'login');

            return ['requires_otp' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        if (
            Settings::get('auth.2fa.enabled', config('innertia.auth.2fa.enabled', false))
            && $user->two_factor_enabled
        ) {
            return ['requires_2fa' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        $token = app(JwtService::class)->generateToken($user, ['context' => $this->context]);

        return ['token' => $token, 'user' => $user];
    }
}
