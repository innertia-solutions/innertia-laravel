<?php

namespace Innertia\Auth\Social;

use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Provisiona (crea/vincula) un usuario cuando el login social no encuentra su email.
 * El default lanza NotFoundException (login-only, comportamiento histórico). Las apps
 * que quieran registro social ligan su propia implementación en el contenedor.
 */
interface SocialProvisioner
{
    /**
     * @param  array<string,mixed>  $state  claims decodificados del state (ej. context, join_token)
     * @return mixed  el modelo User provisionado (o lanza si no se permite crear)
     */
    public function provision(SocialProvider $provider, SocialUser $socialUser, array $state): mixed;
}
