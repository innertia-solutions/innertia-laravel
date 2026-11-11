<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\UseCases\ReplaceFileContents;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
    Route::middleware([])->group(fn () => \Innertia\Files\Routes::register());
    \Innertia\Files\Routes::registerFileServing();
});

afterEach(fn () => innertiaFilesMigrateDown());

it('lists the current version first and then the history', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();

    $res = test()->getJson("/files/{$file->id}/versions")->assertOk();

    expect($res->json('data.0.version'))->toBe(2);
    expect($res->json('data.0.is_current'))->toBeTrue();
    expect($res->json('data.0.source'))->toBe('replace');
    expect($res->json('data.1.version'))->toBe(1);
    expect($res->json('data.1.is_current'))->toBeFalse();
    expect($res->json('data.1.download_url'))->toContain('/versions/1/download');
});

it('answers 404 when listing versions of an unknown file', function () {
    test()->getJson('/files/' . \Illuminate\Support\Str::uuid() . '/versions')->assertNotFound();
});

it('restores a version through the API', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();

    $res = test()->postJson("/files/{$file->id}/versions/1/restore")->assertOk();

    expect($res->json('status'))->toBe('created');
    expect($res->json('file.version'))->toBe(3);
    expect($res->json('file.version_source'))->toBe('restore');
});

it('answers 404 for an unknown version and 422 for a trashed file', function () {
    $file = versionsUpload('uno');

    test()->postJson("/files/{$file->id}/versions/9/restore")->assertNotFound();

    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    $file->trash();
    test()->postJson("/files/{$file->id}/versions/1/restore")->assertStatus(422);
});

it('replaces contents with an upload', function () {
    $file = versionsUpload('uno', 'acta.txt');
    $upload = new UploadedFile(versionsTmpFile('nuevo'), 'otro-nombre.txt', 'text/plain', null, true);

    $res = test()->post("/files/{$file->id}/contents", ['file' => $upload], ['Accept' => 'application/json'])->assertOk();

    expect($res->json('status'))->toBe('created');
    expect($res->json('file.version'))->toBe(2);
    expect($res->json('file.original_name'))->toBe('acta.txt');
});

it('reports unchanged when the upload is identical', function () {
    $file = versionsUpload('uno');
    $upload = new UploadedFile(versionsTmpFile('uno'), 'x.txt', 'text/plain', null, true);

    test()->post("/files/{$file->id}/contents", ['file' => $upload], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('status', 'unchanged');
});

it('rejects replacing contents without a file or on a trashed file', function () {
    $file = versionsUpload('uno');

    test()->postJson("/files/{$file->id}/contents")->assertStatus(422);

    $file->trash();
    $upload = new UploadedFile(versionsTmpFile('otro'), 'x.txt', 'text/plain', null, true);
    test()->post("/files/{$file->id}/contents", ['file' => $upload], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

it('exposes version fields in FileResource', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();

    $res = test()->getJson("/files/{$file->id}")->assertOk();

    expect($res->json('data.version'))->toBe(2);
    expect($res->json('data.versions_count'))->toBe(2);
    expect($res->json('data.version_source'))->toBe('replace');
    expect($res->json('data.can_refresh'))->toBeFalse();

    $list = test()->getJson('/files')->assertOk();
    expect($list->json('data.0.versions_count'))->toBe(2);
});

it('answers 422 (not 500/SQL) for history and restore when versioning is not migrated', function () {
    $file = versionsUpload('uno');
    innertiaFileVersionsMigration()->down();
    \Innertia\Files\Support\FileVersioning::flush();

    $list = test()->getJson("/files/{$file->id}/versions")->assertStatus(422);
    $restore = test()->postJson("/files/{$file->id}/versions/1/restore")->assertStatus(422);

    foreach ([$list, $restore] as $res) {
        expect($res->getContent())->not->toContain('SQLSTATE')->not->toContain('file_versions');
        expect($res->json('message'))->toContain('migrate');
    }
});

it('does not leak SQL errors from the versions API', function () {
    config()->set('app.debug', false);
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    expect(\Innertia\Files\Support\FileVersioning::ready())->toBeTrue();
    \Illuminate\Support\Facades\Schema::drop('file_versions');

    $res = test()->postJson("/files/{$file->id}/versions/1/restore");

    $res->assertStatus(500);
    expect($res->getContent())->not->toContain('SQLSTATE')->not->toContain('file_versions');
});

it('answers 422 when the bytes of the version to restore are missing', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    $v1 = \Innertia\Files\Models\FileVersion::where('file_id', $file->id)->sole();
    Storage::disk('local')->delete($v1->path);

    test()->postJson("/files/{$file->id}/versions/1/restore")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Los bytes de la versión 1 no existen en storage.');
});

it('rejects out-of-range version numbers on restore with 404', function () {
    $file = versionsUpload('uno');

    test()->postJson("/files/{$file->id}/versions/99999999999999999999/restore")->assertNotFound();
});

it('omits download links in the history of a trashed file', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    $file->trash();

    $res = test()->getJson("/files/{$file->id}/versions")->assertOk();

    expect($res->json('data'))->toHaveCount(2);
    expect($res->json('data.0.download_url'))->toBeNull();
    expect($res->json('data.1.download_url'))->toBeNull();
});
