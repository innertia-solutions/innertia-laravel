<?php

use Innertia\Files\Providers\GoogleDriveProvider;
use Innertia\Files\Providers\ProviderConnection;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaProvidersMigrateUp();
    config()->set('innertia.files.providers.google_drive.enabled', true);
    config()->set('innertia.files.providers.google_drive.client_id', '123-abc.apps.googleusercontent.com');
    config()->set('innertia.files.providers.google_drive.client_secret', 'secret');
    config()->set('app.url', 'http://localhost:3900');
});

afterEach(fn () => innertiaProvidersMigrateDown());

it('connectRedirectUrl arma la URL de consentimiento (scope drive.file + offline)', function () {
    $url = app(GoogleDriveProvider::class)->connectRedirectUrl('user', null);
    expect($url)->toContain('accounts.google.com')->toContain('drive.file')->toContain('access_type=offline');
});

it('connectionFor devuelve la conexión de scope user del usuario', function () {
    $uid = (string) \Illuminate\Support\Str::uuid();
    ProviderConnection::create(['provider' => 'google_drive', 'scope' => 'user', 'user_id' => $uid, 'access_token' => 'a']);

    // User es abstract → stub concreto mínimo que solo expone el id.
    $user = new class extends \Innertia\Auth\Models\User {};
    $user->id = $uid;

    $conn = app(GoogleDriveProvider::class)->connectionFor('user', $user, null);
    expect($conn)->not->toBeNull();
    expect($conn->user_id)->toBe($uid);
});

it('pickerConfig expone api_key y token de la conexión', function () {
    config()->set('innertia.files.providers.google_drive.api_key', 'API_KEY');
    $conn = ProviderConnection::create(['provider' => 'google_drive', 'scope' => 'user', 'user_id' => (string) \Illuminate\Support\Str::uuid(), 'access_token' => 'tok']);
    $cfg = app(GoogleDriveProvider::class)->pickerConfig($conn);
    expect($cfg['api_key'])->toBe('API_KEY');
    expect($cfg['token'])->toBe('tok');
});
