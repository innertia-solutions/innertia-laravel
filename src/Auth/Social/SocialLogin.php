<?php

namespace Innertia\Auth\Social;

use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
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

        // 2 — App exists in config
        if (! array_key_exists($this->app, config('innertia.apps', []))) {
            throw new NotFoundException("App '{$this->app}' not found.");
        }

        // 3 — User has access to the app
        if (method_exists($user, 'hasApp') && ! $user->hasApp($this->app)) {
            throw new ForbiddenException("Access to '{$this->app}' is not allowed for this user.");
        }

        // 4 — Generate JWT with provider + app claims
        $token = app(JwtService::class)->generateToken($user, [
            'app'      => $this->app,
            'provider' => $this->provider->value,
        ]);

        return ['token' => $token, 'user' => $user];
    }
}
