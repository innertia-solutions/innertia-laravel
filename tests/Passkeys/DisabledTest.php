<?php

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Routes as AuthRoutes;
use Innertia\Passkeys\Support\PasskeySettings;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    passkeysSetUp();
    passkeysSettingsTable();
    AuthRoutes::publicRoutes('auth');
    AuthRoutes::platformRoutes();
    AuthRoutes::sessionRoutes('auth');
    Route::middleware(\Innertia\Auth\Middleware\Authenticate::class)
        ->group(fn () => \Innertia\Backoffice\Routes::register(''));
});
afterEach(fn () => passkeysTearDown());

it('apagado por BD responde 404 en login y autogestión', function () {
    PasskeySettings::save(['enabled' => false]);

    $this->postJson('auth/passkey/login/options')->assertNotFound();
    $this->postJson('auth/passkey/login')->assertNotFound();
    $this->postJson('platform/auth/passkey/login/options')->assertNotFound();
    $this->getJson('auth/me/passkeys')->assertNotFound();
});

it('apagado, el status público sigue respondiendo enabled=false', function () {
    PasskeySettings::save(['enabled' => false]);

    $this->getJson('auth/passkey/status')->assertOk()->assertExactJson(['enabled' => false]);
    $this->getJson('platform/auth/passkey/status')->assertOk()->assertExactJson(['enabled' => false]);
});

it('encendido (default), el status público responde enabled=true', function () {
    $this->getJson('auth/passkey/status')->assertOk()->assertExactJson(['enabled' => true]);
    $this->getJson('platform/auth/passkey/status')->assertOk()->assertExactJson(['enabled' => true]);
});

it('apagado, las rutas admin siguen registradas (no son 404 de ruta)', function () {
    PasskeySettings::save(['enabled' => false]);

    // Sin token: 401 (la ruta existe y pasa por Authenticate), no 404 por el interruptor.
    $this->getJson('users/1/passkeys')->assertUnauthorized();
    $this->deleteJson('users/1/passkeys')->assertUnauthorized();
});
