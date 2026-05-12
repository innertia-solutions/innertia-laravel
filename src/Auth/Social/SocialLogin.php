<?php

namespace Innertia\Auth\Social;

use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Models\App;
use Innertia\Models\TenantApp;
use Innertia\Platform\Contracts\UseCase;
use Laravel\Socialite\Contracts\User as SocialUser;

class SocialLogin extends UseCase
{
    public function __construct(
        public readonly SocialProvider $provider,
        public readonly SocialUser     $socialUser,
        public readonly string         $app,
    ) {}

    public function execute(): array
    {
        // 1 — Find local user by email from the provider
        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $this->socialUser->getEmail())->first();

        if (! $user) {
            throw new NotFoundException('Account not registered. Please contact your administrator.');
        }

        // 2 — App exists
        $app = App::findByKey($this->app);

        if (! $app) {
            throw new NotFoundException("App '{$this->app}' not found.");
        }

        // 3 — (SaaS) Tenant has the app enabled
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

        // 5 — Generate JWT with provider claim
        $token = app(JwtService::class)->generateToken($user, [
            'app'      => $this->app,
            'provider' => $this->provider->value,
        ]);

        return ['token' => $token, 'user' => $user];
    }
}
