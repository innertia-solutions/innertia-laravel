<?php

namespace Innertia\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Innertia\Auth\Guards\JwtGuard;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Auth\Social\ConfigureSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\MicrosoftAzure\MicrosoftAzureExtendSocialite;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtService::class);
        $this->app->singleton(OtpService::class);
        $this->app->singleton(ConfigureSocialite::class);
    }

    public function boot(): void
    {
        $this->registerJwtGuard();
        $this->registerSuperAdminGate();
        $this->registerMicrosoftSocialiteDriver();
    }

    protected function registerJwtGuard(): void
    {
        Auth::extend('jwt', function ($app, $name, array $config) {
            return new JwtGuard(
                Auth::createUserProvider($config['provider']),
                $app->make('request'),
                $app->make(JwtService::class),
            );
        });
    }

    protected function registerSuperAdminGate(): void
    {
        Gate::before(function ($user, $ability) {
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }
        });
    }

    protected function registerMicrosoftSocialiteDriver(): void
    {
        // socialiteproviders/microsoft-azure registers its driver via an event
        Event::listen(SocialiteWasCalled::class, MicrosoftAzureExtendSocialite::class);
    }
}
