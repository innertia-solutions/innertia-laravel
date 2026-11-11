<?php

use Illuminate\Support\Facades\Route;
use Innertia\Files\Directories\UseCases\CreateDirectory;
use Innertia\Files\Drive\DriveSource;
use Innertia\Files\Drive\Http\FileImportsController;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
    Route::middleware([])->group(function () {
        \Innertia\Files\Routes::register('api/files');
        \Innertia\Files\Routes::registerDriveImport('api');
    });
});

afterEach(fn () => driveTestShutdown());

it('creates an import, runs it (sync queue) and reports it', function () {
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'x', 'A');

    $res = test()->postJson('/api/files/imports', ['items' => ['A']])->assertStatus(202);

    $id = $res->json('data.id');
    test()->getJson("/api/files/imports/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'done')
        ->assertJsonPath('data.imported', 1)
        ->assertJsonPath('data.items.0.path', 'A');
});

it('validates the payload', function () {
    test()->postJson('/api/files/imports', [])->assertStatus(422);
    test()->postJson('/api/files/imports', ['items' => ["a'b"]])->assertStatus(422);
    test()->postJson('/api/files/imports', ['items' => ['a'], 'native_format' => 'zip'])->assertStatus(422);
    test()->postJson('/api/files/imports', ['items' => array_fill(0, 201, 'a')])->assertStatus(422);
});

it('rejects a missing or trashed target directory', function () {
    $this->drive->file('a', 'a.txt', 'x');
    $dir = (new CreateDirectory(null, 'Destino'))->execute();
    $dir->delete();

    test()->postJson('/api/files/imports', ['items' => ['a'], 'target_directory_id' => '00000000-0000-0000-0000-000000000000'])->assertStatus(422);
    test()->postJson('/api/files/imports', ['items' => ['a'], 'target_directory_id' => $dir->id])->assertStatus(422);
});

it('rejects folders when Directories is disabled', function () {
    config()->set('innertia.directories.enabled', false);
    $this->drive->folder('A', 'A');

    test()->postJson('/api/files/imports', ['items' => ['A']])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'carpetas'));
});

it('answers 422 when Drive is not configured', function () {
    app()->offsetUnset(DriveSource::class);

    test()->postJson('/api/files/imports', ['items' => ['a']])->assertStatus(422);
});

it('applies product attributes through the hook', function () {
    $this->drive->file('a', 'a.txt', 'x');
    Route::middleware([])->group(fn () => \Innertia\Files\Routes::registerDriveImport('owned', importsController: DriveHttpOwnedImportsController::class));

    test()->postJson('/owned/files/imports', ['items' => ['a']])->assertStatus(202);

    expect(File::sole()->owner_type)->toBe('platform');
    expect(FileImport::sole()->file_attributes)->toBe(['owner_type' => 'platform']);
});

it('refreshes an imported file and rejects files not from Drive', function () {
    $this->drive->file('a', 'a.txt', 'uno');
    test()->postJson('/api/files/imports', ['items' => ['a']])->assertStatus(202);
    $file = File::sole();
    $this->drive->edit('a', 'dos');

    $res = test()->postJson("/api/files/{$file->id}/refresh")->assertStatus(202);
    test()->getJson('/api/files/imports/' . $res->json('data.id'))->assertJsonPath('data.updated', 1);
    expect($file->fresh()->version)->toBe(2);

    \Illuminate\Support\Facades\Storage::disk('local')->put('l.txt', 'l');
    $local = File::create(['disk' => 'local', 'path' => 'l.txt', 'original_name' => 'l.txt', 'size' => 1]);
    test()->postJson("/api/files/{$local->id}/refresh")->assertStatus(422);
});

it('rejects refreshing a trashed file', function () {
    $this->drive->file('a', 'a.txt', 'x');
    test()->postJson('/api/files/imports', ['items' => ['a']])->assertStatus(202);
    $file = File::sole();
    $file->delete();

    test()->postJson("/api/files/{$file->id}/refresh")
        ->assertStatus(422)
        ->assertJsonPath('message', 'El archivo está en la papelera.');
    test()->postJson('/api/files/00000000-0000-0000-0000-000000000000/refresh')->assertNotFound();
});

it('exposes can_refresh on FileResource only when Drive is available', function () {
    $this->drive->file('a', 'a.txt', 'x');
    test()->postJson('/api/files/imports', ['items' => ['a']]);
    $file = File::sole();

    test()->getJson("/api/files/{$file->id}")->assertJsonPath('data.can_refresh', true);

    app()->offsetUnset(DriveSource::class);
    test()->getJson("/api/files/{$file->id}")->assertJsonPath('data.can_refresh', false);
});

it('hides another user import', function () {
    $import = FileImport::create(['kind' => 'import', 'provider' => 'google_drive', 'source_items' => ['a'], 'created_by' => 'otro']);
    $user = new \Illuminate\Auth\GenericUser(['id' => 'yo']);

    test()->actingAs($user)->getJson("/api/files/imports/{$import->id}")->assertNotFound();
});

class DriveHttpOwnedImportsController extends FileImportsController
{
    protected function importAttributes(\Illuminate\Http\Request $request): array
    {
        return ['owner_type' => 'platform'];
    }
}

it('recovers a stale running import lazily when it is queried', function () {
    config()->set('innertia.files.drive.job_timeout', 60);
    $import = FileImport::create(['kind' => 'import', 'provider' => 'google_drive', 'source_items' => ['a']]);
    $import->markRunning('t');

    test()->getJson("/api/files/imports/{$import->id}")->assertJsonPath('data.status', 'running');

    $this->travel(361)->seconds();
    test()->getJson("/api/files/imports/{$import->id}")
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', 'El proceso no terminó (tiempo excedido).');
});

it('rejects folders or a target directory when directories lacks the provenance columns', function () {
    \Illuminate\Support\Facades\Schema::table('directories', fn ($t) => $t->dropColumn(['provider', 'external_id']));
    \Innertia\Files\Drive\Import\DriveDirectoryMapper::flushSchemaCache();
    $this->drive->folder('A', 'A');
    $this->drive->file('a', 'a.txt', 'x');
    $dir = (new CreateDirectory(null, 'Destino'))->execute();
    $message = 'Faltan las columnas de procedencia en directories: agrega provider y external_id a tu migración de directories (ver innertia:directories:install).';

    test()->postJson('/api/files/imports', ['items' => ['A']])->assertStatus(422)->assertJsonPath('message', $message);
    test()->postJson('/api/files/imports', ['items' => ['a'], 'target_directory_id' => $dir->id])->assertStatus(422)->assertJsonPath('message', $message);
    test()->postJson('/api/files/imports', ['items' => ['a']])->assertStatus(202);
    expect(FileImport::count())->toBe(1);
});

it('treats a bound Drive source that cannot be built as unavailable without breaking file listings', function () {
    $this->drive->file('a', 'a.txt', 'x');
    test()->postJson('/api/files/imports', ['items' => ['a']])->assertStatus(202);
    app()->bind(DriveSource::class, fn () => throw new \RuntimeException('credenciales corruptas'));

    expect(\Innertia\Files\Drive\DriveSourceResolver::available())->toBeFalse();
    test()->getJson('/api/files')->assertOk()->assertJsonPath('data.0.can_refresh', false);
});

it('memoizes Drive availability per request', function () {
    $built = 0;
    app()->bind(DriveSource::class, function () use (&$built) {
        $built++;

        return $this->drive;
    });

    expect(\Innertia\Files\Drive\DriveSourceResolver::available())->toBeTrue();
    expect(\Innertia\Files\Drive\DriveSourceResolver::available())->toBeTrue();
    expect($built)->toBe(1);

    \Innertia\Files\Drive\DriveSourceResolver::flush();
    \Innertia\Files\Drive\DriveSourceResolver::available();
    expect($built)->toBe(2);
});
