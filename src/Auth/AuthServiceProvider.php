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
        // Only register if the microsoft-azure socialite provider package is installed
        if (class_exists(\SocialiteProviders\Manager\SocialiteWasCalled::class) &&
            class_exists(\SocialiteProviders\MicrosoftAzure\MicrosoftAzureExtendSocialite::class)) {
            Event::listen(
                \SocialiteProviders\Manager\SocialiteWasCalled::class,
                \SocialiteProviders\MicrosoftAzure\MicrosoftAzureExtendSocialite::class
            );
        }
    }
}
