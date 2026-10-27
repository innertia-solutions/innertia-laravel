<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Innertia\Workspace\Routes;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Routes::register('wk-test');
});

it('index 409 sin credenciales', function () {
    $this->getJson('/wk-test/users')->assertStatus(409);
});

it('index lista usuarios con creds', function () {
    seedServiceAccount();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
        'admin.googleapis.com/*' => Http::response(['users' => [['id' => '1', 'primaryEmail' => 'a@d.cl', 'name' => ['givenName' => 'A', 'familyName' => 'B']]]]),
    ]);
    $this->getJson('/wk-test/users')->assertOk()->assertJsonPath('users.0.email', 'a@d.cl');
});

it('store valida password min 8', function () {
    seedServiceAccount();
    $this->postJson('/wk-test/users', ['email' => 'x@d.cl', 'given_name' => 'X', 'family_name' => 'Y', 'password' => 'corta'])
        ->assertStatus(422);
});

it('un error de Google devuelve 422 con mensaje', function () {
    seedServiceAccount();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
        'admin.googleapis.com/*' => Http::response(['error' => ['message' => 'Entity already exists.']], 409),
    ]);
    $this->postJson('/wk-test/users', ['email' => 'x@d.cl', 'given_name' => 'X', 'family_name' => 'Y', 'password' => 'Secreta12'])
        ->assertStatus(422)->assertJsonPath('message', 'Entity already exists.');
});
