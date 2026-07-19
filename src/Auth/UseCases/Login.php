<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\Hash;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Exceptions\PlatformAccountException;
use Innertia\Facades\Settings;
use Innertia\Platform\Contracts\UseCase;

class Login extends UseCase
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $context,
    ) {}


    public function execute(): array
    {
        // 1 — Credentials
        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $this->email)->first();

        if (! $user || ! Hash::check($this->password, $user->password)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'Invalid credentials.'], 422)
            );
        }

        // 1b — Rechazo de cuentas de plataforma (opt-in). Tras validar el
        // password: una password incorrecta ya cayó arriba (anti-enumeración).
        if (config('innertia.platform.separate_identity') && $user->is_platform_admin) {
            throw new PlatformAccountException();
        }

        // 2 — Context exists in config
        $contexts = config('innertia.contexts', []);

        if (! array_key_exists($this->context, $contexts)) {
            throw new NotFoundException("Context '{$this->context}' not found.");
        }

        // 3 — User has access to the context
        if (method_exists($user, 'hasContext') && ! $user->hasContext($this->context)) {
            throw new ForbiddenException("Access to '{$this->context}' is not allowed for this user.");
        }

        // 4 — force_password_change
        if ($user->force_password_change) {
            app(OtpService::class)->send($user, 'login');

            return ['requires_password_change' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 5 — Email verification required
        if (
            Settings::get('auth.email_verification.enabled', config('innertia.auth.email_verification.enabled', false))
            && ! $user->email_verified_at
        ) {
            return ['requires_email_verification' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 6 — OTP enabled
        if (Settings::get('auth.otp.enabled', config('innertia.auth.otp.enabled', false))) {
            app(OtpService::class)->send($user, 'login');

            return ['requires_otp' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 7 — 2FA enrolled
        if (
            Settings::get('auth.2fa.enabled', config('innertia.auth.2fa.enabled', false))
            && $user->two_factor_enabled
        ) {
            return ['requires_2fa' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 8 — Issue token with context claim
        $token = app(JwtService::class)->generateToken($user, ['context' => $this->context]);

        return ['token' => $token, 'user' => $user];
    }
}
