<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\Routes as FilesRoutes;
use Innertia\Files\UseCases\ReplaceFileContents;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
    FilesRoutes::registerFileServing();
});

afterEach(fn () => innertiaFilesMigrateDown());

function servedTwoVersions(string $visibility = 'auth'): \Innertia\Files\Models\File
{
    $file = versionsUpload('uno', 'nota.txt');
    $file->forceFill(['visibility' => $visibility])->save();
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();

    return $file->fresh();
}

it('downloads an old version via its signed path', function () {
    $file = servedTwoVersions();
    $v1 = FileVersion::where('file_id', $file->id)->sole();

    $res = test()->get($v1->signedDownloadPath());

    $res->assertOk();
    expect($res->streamedContent())->toBe('uno');
    expect($res->headers->get('Content-Disposition'))->toContain('attachment');
});

it('serves the current version through the versions route too', function () {
    $file = servedTwoVersions('public');

    $res = test()->get("/files/{$file->id}/versions/2/download");

    $res->assertOk();
    expect($res->streamedContent())->toBe('dos');
});

it('requires auth without a signature (401) and rejects a bad signature (403)', function () {
    $file = servedTwoVersions();

    test()->get("/files/{$file->id}/versions/1/download")->assertStatus(401);
    test()->get("/files/{$file->id}/versions/1/download?signature=bad")->assertStatus(403);
});

it('returns 404 for an unknown version', function () {
    $file = servedTwoVersions('public');

    test()->get("/files/{$file->id}/versions/9/download")->assertNotFound();
});

it('keeps the original file serving routes working', function () {
    $file = servedTwoVersions();

    $res = test()->get($file->signedViewUrl());

    $res->assertOk();
    expect($res->streamedContent())->toBe('dos');
});

it('signs version URLs against the version route', function () {
    $file = servedTwoVersions();
    $url = url(URL::temporarySignedRoute('innertia.files.versions.view', now()->addMinute(), ['id' => $file->id, 'version' => 1], absolute: false));

    test()->get($url)->assertOk();
});

it('strips quotes and newlines from the Content-Disposition filename', function () {
    $file = versionsUpload('uno', 'no"ta.txt');
    $file->forceFill(['visibility' => 'public'])->save();

    $res = test()->get("/files/{$file->id}/versions/{$file->version}/download");

    $res->assertOk();
    expect($res->headers->get('Content-Disposition'))->toBe('attachment; filename="nota.txt"');
});

it('answers 404 (not 500) on the versions route when versioning is not migrated', function () {
    $file = versionsUpload('uno');
    $file->forceFill(['visibility' => 'public'])->save();
    innertiaFileVersionsMigration()->down();
    \Innertia\Files\Support\FileVersioning::flush();

    $res = test()->get("/files/{$file->id}/versions/1/download");

    $res->assertNotFound();
    expect($res->getContent())->not->toContain('SQLSTATE');
});

it('answers 404 for an out-of-range version number', function () {
    $file = servedTwoVersions('public');

    test()->get("/files/{$file->id}/versions/99999999999999999999/download")->assertNotFound();
    test()->get("/files/{$file->id}/versions/99999999999999999999/view")->assertNotFound();
});
