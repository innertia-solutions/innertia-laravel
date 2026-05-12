<?php

namespace Innertia\Auth\Social;

use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Settings;

class ConfigureSocialite
{
    /**
     * Read credentials from Settings (DB) and wire them into Laravel's
     * services config so Socialite can pick them up before each redirect/callback.
     *
     * @throws ForbiddenException if the provider is disabled or not configured.
     */
    public function configure(SocialProvider $provider): void
    {
        $key = $provider->value;

        $enabled      = Settings::get("auth.{$key}.enabled", false);
        $clientId     = Settings::get("auth.{$key}.client_id");
        $clientSecret = Settings::get("auth.{$key}.client_secret");

        if (! $enabled) {
            throw new ForbiddenException("{$provider->label()} login is not enabled.");
        }

        if (! $clientId || ! $clientSecret) {
            throw new ForbiddenException("{$provider->label()} login is not configured.");
        }

        $redirectUrl = url("/auth/{$key}/callback");

        // For Microsoft (azure) the services key must match the driver name
        $serviceKey = $provider->driver();

        config([
            "services.{$serviceKey}.client_id"     => $clientId,
            "services.{$serviceKey}.client_secret" => $clientSecret,
            "services.{$serviceKey}.redirect"      => $redirectUrl,
        ]);
    }
}
