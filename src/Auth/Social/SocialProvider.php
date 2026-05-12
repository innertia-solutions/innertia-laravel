<?php

namespace Innertia\Auth\Social;

enum SocialProvider: string
{
    case Google    = 'google';
    case Microsoft = 'microsoft';
    case Github    = 'github';

    /**
     * The Socialite driver name — differs from the URL segment for Microsoft
     * because we use socialiteproviders/microsoft-azure (driver: 'azure').
     */
    public function driver(): string
    {
        return match($this) {
            self::Microsoft => 'azure',
            default         => $this->value,
        };
    }

    public function label(): string
    {
        return match($this) {
            self::Google    => 'Google',
            self::Microsoft => 'Microsoft',
            self::Github    => 'GitHub',
        };
    }
}
