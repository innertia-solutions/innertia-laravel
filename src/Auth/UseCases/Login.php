<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\Hash;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Facades\Settings;
use Innertia\Models\App;
use Innertia\Models\TenantApp;
use Innertia\Platform\Contracts\UseCase;

class Login extends UseCase
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $app,
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

        // 2 — App exists and is active
        $app = App::findByKey($this->app);

        if (! $app) {
            throw new NotFoundException("App '{$this->app}' not found.");
        }

        // 3 — (saas) Tenant has the app enabled
        if (config('innertia.mode') === 'saas') {
            $tenantId = function_exists('tenant') ? tenant('id') : null;

            $tenantHasApp = TenantApp::where('tenant_id', $tenantId)
                ->where('app_id', $app->id)
                ->where('active', true)
                ->exists();

            if (! $tenantHasApp) {
                throw new ForbiddenException("App '{$this->app}' is not available for this tenant.");
            }
        }

        // 4 — User has access to the app
        if (! $user->hasApp($this->app)) {
            throw new ForbiddenException("Access to '{$this->app}' is not allowed for this user.");
        }

        // 5 — force_password_change: OTP always sent regardless of otp.enabled
        if ($user->force_password_change) {
            app(OtpService::class)->send($user, 'login');

            return ['requires_password_change' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 6 — Email verification required
        if (
            Settings::get('auth.email_verification.enabled', config('innertia.auth.email_verification.enabled', false))
            && ! $user->email_verified_at
        ) {
            return ['requires_email_verification' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 7 — OTP enabled
        if (Settings::get('auth.otp.enabled', config('innertia.auth.otp.enabled', false))) {
            app(OtpService::class)->send($user, 'login');

            return ['requires_otp' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 8 — 2FA enrolled
        if (
            Settings::get('auth.2fa.enabled', config('innertia.auth.2fa.enabled', false))
            && $user->two_factor_enabled
        ) {
            return ['requires_2fa' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 9 — Issue token with app claim
        $token = app(JwtService::class)->generateToken($user, ['app' => $this->app]);

        return ['token' => $token, 'user' => $user];
    }
}
