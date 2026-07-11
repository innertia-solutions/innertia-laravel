<?php

namespace Innertia\Auth\Social;

use Innertia\Exceptions\NotFoundException;
use Laravel\Socialite\Contracts\User as SocialUser;

/** Default: no crea usuarios (login-only). Preserva el comportamiento histórico. */
class DefaultSocialProvisioner implements SocialProvisioner
{
    public function provision(SocialProvider $provider, SocialUser $socialUser, array $state): mixed
    {
        throw new NotFoundException('Account not registered. Please contact your administrator.');
    }
}
