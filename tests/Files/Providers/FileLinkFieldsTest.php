<?php

use Innertia\Files\Models\File;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaProvidersMigrateUp();
});
afterEach(fn () => innertiaProvidersMigrateDown());

it('persiste los campos de enlace a provider', function () {
    $f = File::create([
        'disk' => 'local', 'path' => 'x.pdf', 'original_name' => 'x.pdf',
        'provider' => 'google_drive', 'link_mode' => 'reference',
        'external_id' => 'abc', 'external_url' => 'https://drive/abc',
    ]);
    $fresh = $f->fresh();
    expect($fresh->provider)->toBe('google_drive');
    expect($fresh->link_mode)->toBe('reference');
    expect($fresh->external_id)->toBe('abc');
});

it('link_mode default es local', function () {
    $f = File::create(['disk'=>'local','path'=>'y.pdf','original_name'=>'y.pdf']);
    expect($f->fresh()->link_mode)->toBe('local');
});
