<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Innertia\Facades\Settings;
use Innertia\Files\Models\File;
use Innertia\Storage\StorageManager;

uses(RefreshDatabase::class);

/** Disco cloud multi-proveedor (R2/S3/GCS) configurado desde Settings. */

function cloudSettings(array $overrides = []): array
{
    return array_merge([
        'provider' => 'r2', 'bucket' => 'b', 'region' => null,
        'endpoint' => 'https://acc.r2.cloudflarestorage.com',
        'access_key_id' => 'AK', 'secret_access_key' => 'SK', 'public_url' => null,
    ], $overrides);
}

function saveCloudSettings(array $s): void
{
    foreach ($s as $k => $v) {
        if ($v !== null) {
            Settings::set("integrations.storage.{$k}", $v, 'string', encrypted: $k === 'secret_access_key');
        }
    }
}

it('arma la config por proveedor', function () {
    $m = app(StorageManager::class);

    $r2 = $m->buildCloudConfig(cloudSettings());
    expect($r2)->toMatchArray(['driver' => 's3', 'region' => 'auto', 'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true, 'key' => 'AK', 'secret' => 'SK', 'bucket' => 'b', 'throw' => true]);

    $s3 = $m->buildCloudConfig(cloudSettings(['provider' => 's3', 'endpoint' => null, 'region' => 'us-east-1']));
    expect($s3)->toMatchArray(['region' => 'us-east-1', 'use_path_style_endpoint' => false])
        ->and($s3)->not->toHaveKey('endpoint');

    $gcs = $m->buildCloudConfig(cloudSettings(['provider' => 'gcs', 'endpoint' => null]));
    expect($gcs)->toMatchArray(['endpoint' => StorageManager::GCS_ENDPOINT, 'region' => 'auto']);
});

it('devuelve null si falta algo obligatorio', function () {
    $m = app(StorageManager::class);

    expect($m->buildCloudConfig(cloudSettings(['provider' => 'ftp'])))->toBeNull()
        ->and($m->buildCloudConfig(cloudSettings(['bucket' => null])))->toBeNull()
        ->and($m->buildCloudConfig(cloudSettings(['endpoint' => null])))->toBeNull()                       // r2 sin endpoint
        ->and($m->buildCloudConfig(cloudSettings(['provider' => 's3', 'region' => null])))->toBeNull();   // s3 sin región

    expect($m->probe(cloudSettings(['provider' => 's3', 'region' => null, 'secret_access_key' => null])))
        ->toMatchArray(['ok' => false, 'message' => 'Falta: Secret Access Key, región.']);
});

it('hidrata el disco cloud y lo deja como default', function () {
    config(['innertia.storage.cloud.enabled' => true, 'filesystems.default' => 'local']);
    saveCloudSettings(cloudSettings(['provider' => 'gcs', 'endpoint' => null]));

    $m = app(StorageManager::class);
    $m->hydrateCloud();

    expect(config('filesystems.default'))->toBe('cloud')
        ->and(config('filesystems.disks.cloud.endpoint'))->toBe(StorageManager::GCS_ENDPOINT)
        ->and(config('filesystems.disks.cloud.secret'))->toBe('SK')
        ->and($m->activeDisk())->toBe('cloud')
        ->and($m->cloudConfigured())->toBeTrue();
});

it('no hace nada si el feature está apagado o incompleto', function () {
    config(['filesystems.default' => 'local']);
    saveCloudSettings(cloudSettings());

    config(['innertia.storage.cloud.enabled' => false]);
    app(StorageManager::class)->hydrateCloud();
    expect(config('filesystems.default'))->toBe('local');

    config(['innertia.storage.cloud.enabled' => true]);
    Settings::forget('integrations.storage.bucket');
    app(StorageManager::class)->hydrateCloud();
    expect(config('filesystems.default'))->toBe('local');
});

it('re-hidratar sin cambios no descarta el disco ya resuelto', function () {
    config(['innertia.storage.cloud.enabled' => true]);
    saveCloudSettings(cloudSettings());
    $m = app(StorageManager::class);
    $m->hydrateCloud();
    Storage::fake('cloud');
    Storage::disk('cloud')->put('x.txt', 'ok');

    $m->hydrateCloud(); // p. ej. JobProcessing de un job síncrono

    expect(Storage::disk('cloud')->get('x.txt'))->toBe('ok');
});

it('localPath devuelve la ruta real en discos locales', function () {
    Storage::fake('local');
    Storage::disk('local')->put('a/b.csv', 'x');

    expect(file_get_contents(app(StorageManager::class)->localPath('local', 'a/b.csv')))->toBe('x');
});

describe('migración de archivos', function () {
    beforeEach(function () {
        config()->set('innertia.mode', 'app');
        require_once __DIR__.'/../Files/helpers/migrate.php';
        // RefreshDatabase ya puede traer files/entity_permissions según el modo.
        if (! \Illuminate\Support\Facades\Schema::hasTable('files')) {
            innertiaFilesMigrateUp();
            $this->createdFiles = true;
        }
        config(['innertia.storage.cloud.enabled' => true]);
        saveCloudSettings(cloudSettings());
        Storage::fake('local');
        Storage::fake('cloud');
    });

    afterEach(function () {
        if ($this->createdFiles ?? false) {
            innertiaFilesMigrateDown();
        }
    });

    it('copia al cloud, actualiza el disk y es idempotente', function () {
        Storage::disk('local')->put('files/2026/11/a.pdf', 'PDF');
        $ok = File::create(['disk' => 'local', 'path' => 'files/2026/11/a.pdf', 'original_name' => 'a.pdf']);
        $ghost = File::create(['disk' => 'local', 'path' => 'files/x.pdf', 'original_name' => 'x.pdf']);

        $this->artisan('innertia:storage:migrate-files')->assertSuccessful();

        expect($ok->fresh()->disk)->toBe('cloud')
            ->and(Storage::disk('cloud')->get('files/2026/11/a.pdf'))->toBe('PDF')
            ->and(Storage::disk('local')->exists('files/2026/11/a.pdf'))->toBeTrue()  // conserva el origen
            ->and($ghost->fresh()->disk)->toBe('local');                              // sin contenido: no se toca

        $this->artisan('innertia:storage:migrate-files --dry-run')
            ->expectsOutputToContain('1 archivo(s)')->assertSuccessful(); // solo queda el fantasma
    });

    it('falla si el cloud no está configurado', function () {
        Settings::forget('integrations.storage.bucket');
        $this->artisan('innertia:storage:migrate-files')->assertFailed();
    });
});
