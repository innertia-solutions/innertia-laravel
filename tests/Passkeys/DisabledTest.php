<?php

use Innertia\Auth\Routes as AuthRoutes;

it('sin habilitar no registra rutas de passkeys', function () {
    config()->set('innertia.passkeys.enabled', false);
    AuthRoutes::publicRoutes('auth');
    AuthRoutes::platformRoutes();
    AuthRoutes::sessionRoutes('auth');
    \Innertia\Backoffice\Routes::register('');

    $this->postJson('auth/passkey/login/options')->assertNotFound();
    $this->postJson('platform/auth/passkey/login/options')->assertNotFound();
    $this->getJson('auth/me/passkeys')->assertNotFound();
    $this->getJson('users/1/passkeys')->assertNotFound();
});
