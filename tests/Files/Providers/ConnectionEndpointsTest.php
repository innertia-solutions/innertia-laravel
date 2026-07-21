<?php

use Illuminate\Support\Facades\Route;
use Innertia\Files\Providers\ProviderConnection;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaProvidersMigrateUp();
    config()->set('innertia.files.providers.google_drive.enabled', true);
    Route::middleware([])->group(fn () => \Innertia\Files\Routes::registerProviderConnections());
});
afterEach(fn () => innertiaProvidersMigrateDown());

it('status devuelve connected=false sin conexión', function () {
    test()->getJson('/files/providers/google_drive/status')->assertOk()->assertJsonPath('connected', false);
});

it('disconnect borra la conexión de scope user (por user_id)', function () {
    $uid = (string) \Illuminate\Support\Str::uuid();
    ProviderConnection::create(['provider'=>'google_drive','scope'=>'user','user_id'=>$uid,'access_token'=>'a']);
    test()->deleteJson('/files/providers/google_drive/connection?scope=user&user_id='.$uid)->assertOk()->assertJsonPath('connected', false);
    expect(ProviderConnection::count())->toBe(0);
});
