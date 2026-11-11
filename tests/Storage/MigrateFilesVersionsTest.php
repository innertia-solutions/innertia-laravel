<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Innertia\Facades\Settings;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\UseCases\ReplaceFileContents;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../Files/helpers/migrate.php';
    require_once __DIR__ . '/../Files/helpers/versions.php';
    // RefreshDatabase puede traer una tabla files incompleta: se recrea con el esquema completo.
    foreach (['file_versions', 'files', 'entity_permissions'] as $t) {
        \Illuminate\Support\Facades\Schema::dropIfExists($t);
    }
    innertiaFilesMigrateUp();

    config(['innertia.storage.cloud.enabled' => true]);
    foreach ([
        'provider' => 'r2', 'bucket' => 'b', 'endpoint' => 'https://acc.r2.cloudflarestorage.com',
        'access_key_id' => 'AK', 'secret_access_key' => 'SK',
    ] as $k => $v) {
        Settings::set("integrations.storage.{$k}", $v, 'string', encrypted: $k === 'secret_access_key');
    }
    Storage::fake('local');
    Storage::fake('cloud');
});


function fileWithVersions(): \Innertia\Files\Models\File
{
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    (new ReplaceFileContents($file, versionsTmpFile('tres'), FileVersionSource::Replace))->execute();

    return $file->fresh();
}

it('migrates superseded versions to the cloud disk and keeps the source', function () {
    $file = fileWithVersions();

    $this->artisan('innertia:storage:migrate-files')
        ->expectsOutputToContain('2 versión(es)')
        ->expectsOutputToContain('Versiones migradas: 2 · sin contenido: 0 · errores: 0')
        ->assertSuccessful();

    foreach (FileVersion::where('file_id', $file->id)->get() as $v) {
        expect($v->disk)->toBe('cloud')
            ->and(Storage::disk('cloud')->exists($v->path))->toBeTrue()
            ->and(Storage::disk('local')->exists($v->path))->toBeTrue();
    }
    expect(Storage::disk('cloud')->get(FileVersion::where('file_id', $file->id)->where('version', 1)->first()->path))->toBe('uno');
});

it('deletes the source bytes of versions with --delete-source', function () {
    $file = fileWithVersions();
    $paths = FileVersion::where('file_id', $file->id)->pluck('path')->all();

    $this->artisan('innertia:storage:migrate-files --delete-source')->assertSuccessful();

    foreach ($paths as $path) {
        expect(Storage::disk('local')->exists($path))->toBeFalse()
            ->and(Storage::disk('cloud')->exists($path))->toBeTrue();
    }
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('changes nothing on dry-run', function () {
    $file = fileWithVersions();

    $this->artisan('innertia:storage:migrate-files --dry-run')
        ->expectsOutputToContain('Versiones migradas: 2')
        ->assertSuccessful();

    expect(FileVersion::where('file_id', $file->id)->where('disk', 'local')->count())->toBe(2)
        ->and(Storage::disk('cloud')->allFiles())->toBe([])
        ->and($file->fresh()->disk)->toBe('local');
});

it('counts versions without bytes and does not fail', function () {
    $file = fileWithVersions();
    $ghost = FileVersion::where('file_id', $file->id)->where('version', 1)->first();
    Storage::disk('local')->delete($ghost->path);

    $this->artisan('innertia:storage:migrate-files')
        ->expectsOutputToContain('Versiones migradas: 1 · sin contenido: 1 · errores: 0')
        ->assertSuccessful();

    expect($ghost->fresh()->disk)->toBe('local');
});
