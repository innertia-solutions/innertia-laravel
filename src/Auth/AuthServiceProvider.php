<?php

namespace Innertia\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Innertia\Auth\Guards\JwtGuard;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtService::class);
        $this->app->singleton(OtpService::class);
    }

    public function boot(): void
    {
        $this->registerJwtGuard();
        $this->registerSuperAdminGate();
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
}
