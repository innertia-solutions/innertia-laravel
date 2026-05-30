<?php

use Illuminate\Support\Facades\Route;
use Innertia\App\Http\Controllers\StatusController;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    Route::get('status', [StatusController::class, 'status']);
});

it('expone demo cuando está habilitado y hay credenciales', function () {
    config()->set('innertia.demo', [
        'enabled'  => true,
        'email'    => 'demo@sumando.test',
        'password' => 'Demo1234!',
    ]);

    $this->getJson('/status')
        ->assertOk()
        ->assertJsonPath('branding.demo.email', 'demo@sumando.test')
        ->assertJsonPath('branding.demo.password', 'Demo1234!');
});

it('devuelve demo null cuando está deshabilitado', function () {
    config()->set('innertia.demo', [
        'enabled'  => false,
        'email'    => 'demo@sumando.test',
        'password' => 'Demo1234!',
    ]);

    $this->getJson('/status')
        ->assertOk()
        ->assertJsonPath('branding.demo', null);
});

it('devuelve demo null cuando faltan credenciales', function () {
    config()->set('innertia.demo', ['enabled' => true, 'email' => null, 'password' => null]);

    $this->getJson('/status')
        ->assertOk()
        ->assertJsonPath('branding.demo', null);
});
