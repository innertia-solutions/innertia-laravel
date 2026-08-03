<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Facades\Settings;
use Innertia\Storage\StorageManager;

uses(RefreshDatabase::class);

it('hidrata el disco r2 desde settings', function () {
    config(['innertia.storage.from_settings' => [
        'r2' => [
            'integrations.r2.access_key_id'     => 'key',
            'integrations.r2.secret_access_key' => 'secret',
            'integrations.r2.bucket'            => 'bucket',
            'integrations.r2.endpoint'          => 'endpoint',
        ],
    ]]);
    config(['filesystems.disks.r2' => ['driver' => 's3', 'key' => null, 'secret' => null, 'bucket' => null, 'endpoint' => null]]);

    Settings::set('integrations.r2.access_key_id', 'AK', 'string', encrypted: true);
    Settings::set('integrations.r2.secret_access_key', 'SK', 'string', encrypted: true);
    Settings::set('integrations.r2.bucket', 'mybucket', 'string');
    Settings::set('integrations.r2.endpoint', 'https://r2.example', 'string');

    app(StorageManager::class)->hydrate();

    expect(config('filesystems.disks.r2.key'))->toBe('AK');
    expect(config('filesystems.disks.r2.bucket'))->toBe('mybucket');
});

it('no toca el disco si faltan settings', function () {
    config(['innertia.storage.from_settings' => [
        'r2' => ['integrations.r2.access_key_id' => 'key', 'integrations.r2.bucket' => 'bucket'],
    ]]);
    config(['filesystems.disks.r2' => ['driver' => 's3', 'key' => 'ENVKEY', 'bucket' => 'envbucket']]);

    // Solo una de las dos → no hidrata.
    Settings::set('integrations.r2.access_key_id', 'AK', 'string', encrypted: true);

    app(StorageManager::class)->hydrate();

    expect(config('filesystems.disks.r2.key'))->toBe('ENVKEY'); // intacto
});

it('activeDisk resuelve cloud o fallback', function () {
    config(['innertia.storage.cloud_disk' => 'r2', 'innertia.storage.fallback_disk' => 'public']);
    config(['innertia.storage.from_settings' => ['r2' => ['integrations.r2.bucket' => 'bucket']]]);
    config(['filesystems.disks.r2' => ['driver' => 's3', 'bucket' => null]]);

    expect(app(StorageManager::class)->activeDisk())->toBe('public'); // sin settings

    Settings::set('integrations.r2.bucket', 'b', 'string');
    // configured() exige bucket+key; para activar r2 necesitamos ambos hidratados.
    config(['innertia.storage.from_settings' => ['r2' => [
        'integrations.r2.bucket' => 'bucket',
        'integrations.r2.access_key_id' => 'key',
    ]]]);
    Settings::set('integrations.r2.access_key_id', 'AK', 'string', encrypted: true);

    app(StorageManager::class)->hydrate();
    expect(app(StorageManager::class)->activeDisk())->toBe('r2'); // con settings
});
