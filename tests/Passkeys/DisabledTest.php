<?php

use Innertia\Auth\Routes as AuthRoutes;

it('sin habilitar no registra rutas de passkeys', function () {
    config()->set('innertia.passkeys.enabled', false);
    AuthRoutes::publicRoutes('auth');

    $this->postJson('auth/passkey/login/options')->assertNotFound();
});
