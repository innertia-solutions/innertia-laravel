<?php

use Innertia\Files\Providers\ProviderConnection;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaProvidersMigrateUp();
});

afterEach(fn () => innertiaProvidersMigrateDown());

it('cifra access_token en la DB y lo descifra al leer', function () {
    $c = ProviderConnection::create([
        'provider' => 'google_drive',
        'scope' => 'user',
        'user_id' => (string) \Illuminate\Support\Str::uuid(),
        'access_token' => 'ya29.secreto',
        'refresh_token' => 'refresh.secreto',
    ]);
    $raw = \Illuminate\Support\Facades\DB::table('provider_connections')->where('id', $c->id)->value('access_token');
    expect($raw)->not->toBe('ya29.secreto');
    expect($c->fresh()->access_token)->toBe('ya29.secreto');
});

it('isExpired es true cuando expires_at pasó', function () {
    $c = new ProviderConnection(['expires_at' => now()->subMinute()]);
    expect($c->isExpired())->toBeTrue();
});

it('isExpired es false cuando no hay expires_at', function () {
    expect((new ProviderConnection())->isExpired())->toBeFalse();
});
