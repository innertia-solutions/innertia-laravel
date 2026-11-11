<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Auth\Routes as AuthRoutes;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Tests\Passkeys\Support\FakeAuthenticator;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    passkeysSetUp();
    Mail::fake();
    AuthRoutes::publicRoutes('auth');
    Route::middleware(\Innertia\Auth\Middleware\Authenticate::class)->group(fn () => AuthRoutes::sessionRoutes('auth'));
    AuthRoutes::platformRoutes();
});
afterEach(fn () => passkeysTearDown());

function pkHttpBearer($user, array $claims = ['context' => 'backoffice']): array
{
    // El guard jwt cachea el usuario entre requests dentro de un mismo test.
    app('auth')->forgetGuards();
    $token = app(\Innertia\Auth\Services\JwtService::class)->generateToken($user, $claims);

    return ['Authorization' => "Bearer {$token}"];
}

function pkRegisterViaHttp($user, FakeAuthenticator $auth, string $name = 'Mac', string $base = 'auth', array $claims = ['context' => 'backoffice']): array
{
    $opts = test()->postJson("{$base}/me/passkeys/options", [], pkHttpBearer($user, $claims))->assertOk()->json();

    return test()->postJson("{$base}/me/passkeys", [
        'key' => $opts['key'], 'response' => $auth->register($opts['options']), 'name' => $name,
    ], pkHttpBearer($user, $claims))->assertCreated()->json();
}

it('registra, lista, renombra y borra las passkeys propias', function () {
    $user = passkeyUser();
    $created = pkRegisterViaHttp($user, new FakeAuthenticator());

    expect($created)->toHaveKeys(['id', 'name', 'provider', 'created_at', 'last_used_at'])
        ->and($created)->not->toHaveKey('credential');

    $this->getJson('auth/me/passkeys', pkHttpBearer($user))->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson("auth/me/passkeys/{$created['id']}", ['name' => 'Notebook'], pkHttpBearer($user))
        ->assertOk()->assertJsonPath('name', 'Notebook');
    $this->deleteJson("auth/me/passkeys/{$created['id']}", [], pkHttpBearer($user))->assertNoContent();

    expect(Passkey::count())->toBe(0);
});

it('no deja tocar passkeys de otro usuario', function () {
    $ana = passkeyUser();
    $created = pkRegisterViaHttp($ana, new FakeAuthenticator());

    $this->deleteJson("auth/me/passkeys/{$created['id']}", [], pkHttpBearer(passkeyUser(['name' => 'Beto'])))->assertNotFound();
});

it('ingresa por HTTP con contextos y devuelve el contexto elegido', function () {
    $user = passkeyUser();
    $auth = new FakeAuthenticator();
    pkRegisterViaHttp($user, $auth);

    $opts = $this->postJson('auth/passkey/login/options')->assertOk()->json();
    $this->postJson('auth/passkey/login', [
        'key' => $opts['key'], 'response' => $auth->assert($opts['options']), 'contexts' => ['backoffice'],
    ])->assertOk()->assertJsonStructure(['token', 'user', 'context'])->assertJsonPath('context', 'backoffice');
});

it('ingreso fallido responde 422 genérico', function () {
    $opts = $this->postJson('auth/passkey/login/options')->json();

    $this->postJson('auth/passkey/login', [
        'key' => $opts['key'], 'response' => (new FakeAuthenticator())->assert($opts['options']), 'contexts' => ['backoffice'],
    ])->assertStatus(422)->assertJsonPath('message', 'No pudimos verificar la passkey.');
});

it('ingreso de plataforma por HTTP', function () {
    $admin = passkeyUser(['is_platform_admin' => true]);
    $auth = new FakeAuthenticator();
    pkRegisterViaHttp($admin, $auth);

    $opts = $this->postJson('platform/auth/passkey/login/options')->assertOk()->json();
    $this->postJson('platform/auth/passkey/login', [
        'key' => $opts['key'], 'response' => $auth->assert($opts['options']),
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('la cuenta de plataforma gestiona sus passkeys con token platform', function () {
    $admin = passkeyUser(['is_platform_admin' => true]);
    $claims = ['platform' => true];
    $created = pkRegisterViaHttp($admin, new FakeAuthenticator(), 'Llave', 'platform/auth', $claims);

    $this->getJson('platform/auth/me/passkeys', pkHttpBearer($admin, $claims))->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("platform/auth/me/passkeys/{$created['id']}", [], pkHttpBearer($admin, $claims))->assertNoContent();
});

it('valida el body', function () {
    $this->postJson('auth/passkey/login', [])->assertStatus(422)->assertJsonValidationErrors(['key', 'response', 'contexts']);
});
