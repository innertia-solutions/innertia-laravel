<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Events\FileEvent;
use Innertia\Files\Exceptions\FileTrashedException;
use Innertia\Files\Exceptions\FileVersioningNotMigratedException;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\Support\FileVersioning;
use Innertia\Files\UseCases\ReplaceFileContents;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

function replaceWith(File $file, string $contents, FileVersionSource $source = FileVersionSource::Replace, array $meta = [])
{
    return (new ReplaceFileContents($file, versionsTmpFile($contents), $source, meta: $meta))->execute();
}

it('creates version 2 and archives version 1 keeping the same id', function () {
    $file = versionsUpload('uno');
    $oldPath = $file->path;

    $result = replaceWith($file, 'dos');

    expect($result->wasCreated())->toBeTrue();
    expect($result->file->id)->toBe($file->id);
    expect($result->file->version)->toBe(2);
    expect($result->file->checksum)->toBe(hash('sha256', 'dos'));
    expect($result->file->path)->not->toBe($oldPath);
    expect($result->file->version_source)->toBe('replace');
    expect(Storage::disk('local')->get($result->file->path))->toBe('dos');

    $v1 = FileVersion::where('file_id', $file->id)->sole();
    expect($v1->version)->toBe(1);
    expect($v1->path)->toBe($oldPath);
    expect($v1->checksum)->toBe(hash('sha256', 'uno'));
    expect($v1->source)->toBe('upload');
    expect($v1->superseded_at)->not->toBeNull();
    expect(Storage::disk('local')->get($v1->path))->toBe('uno');
});

it('syncs the caller instance with the new version', function () {
    $file = versionsUpload('uno');

    replaceWith($file, 'dos');

    expect($file->version)->toBe(2);
});

it('returns unchanged and writes nothing when contents are identical', function () {
    $file = versionsUpload('igual');
    $filesBefore = Storage::disk('local')->allFiles();

    $result = replaceWith($file, 'igual');

    expect($result->wasCreated())->toBeFalse();
    expect($result->status)->toBe('unchanged');
    expect($file->fresh()->version)->toBe(1);
    expect(FileVersion::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe($filesBefore);
});

it('computes a missing checksum lazily before comparing', function () {
    $file = versionsUpload('legado');
    $file->forceFill(['checksum' => null])->saveQuietly();

    $result = replaceWith($file, 'legado');

    expect($result->status)->toBe('unchanged');
    expect($file->fresh()->checksum)->toBe(hash('sha256', 'legado'));
});

it('still replaces when the current bytes are missing from storage', function () {
    $file = versionsUpload('perdido');
    $oldPath = $file->path;
    $file->forceFill(['checksum' => null])->saveQuietly();
    Storage::disk('local')->delete($oldPath);

    $result = replaceWith($file, 'nuevo');

    expect($result->wasCreated())->toBeTrue();
    expect($result->file->version)->toBe(2);
    expect(Storage::disk('local')->get($result->file->path))->toBe('nuevo');

    $v1 = FileVersion::where('file_id', $file->id)->sole();
    expect($v1->path)->toBe($oldPath);
    expect($v1->checksum)->toBeNull();
});

it('never repeats a version number with a stale instance', function () {
    $file = versionsUpload('uno');
    $staleA = File::find($file->id);
    $staleB = File::find($file->id);

    replaceWith($staleA, 'dos');
    $result = replaceWith($staleB, 'tres');

    expect($result->file->version)->toBe(3);
    expect(FileVersion::where('file_id', $file->id)->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

it('treats a concurrent identical replacement as unchanged', function () {
    $file = versionsUpload('uno');
    $stale = File::find($file->id);

    replaceWith($file, 'dos');
    $result = replaceWith($stale, 'dos');

    expect($result->status)->toBe('unchanged');
    expect(File::find($file->id)->version)->toBe(2);
});

it('applies meta (restored_from_version, external_*, original_name)', function () {
    $file = versionsUpload('uno', 'a.txt');

    $result = replaceWith($file, 'dos', FileVersionSource::ProviderRefresh, [
        'external_revision'    => 'rev-9',
        'external_export_mime' => 'application/pdf',
        'external_synced_at'   => now(),
        'original_name'        => 'b.txt',
    ]);

    expect($result->file->external_revision)->toBe('rev-9');
    expect($result->file->external_export_mime)->toBe('application/pdf');
    expect($result->file->external_synced_at)->not->toBeNull();
    expect($result->file->original_name)->toBe('b.txt');
});

it('keeps the name when meta does not ask to change it', function () {
    $file = versionsUpload('uno', 'informe.txt');

    expect(replaceWith($file, 'dos')->file->original_name)->toBe('informe.txt');
});

it('rejects a trashed file', function () {
    $file = versionsUpload('uno');
    $file->trash();

    replaceWith($file->fresh(), 'dos');
})->throws(FileTrashedException::class);

it('deletes the new bytes when the database write fails', function () {
    $file = versionsUpload('uno');
    $before = Storage::disk('local')->allFiles();
    Schema::drop('file_versions'); // FileVersioning sigue cacheado como "ready"

    expect(fn () => replaceWith($file, 'dos'))->toThrow(\Illuminate\Database\QueryException::class);

    expect(Storage::disk('local')->allFiles())->toBe($before);
    expect(File::find($file->id)->version)->toBe(1);
});

it('fails clearly when versioning is not migrated', function () {
    $file = versionsUpload('uno');
    innertiaFileVersionsMigration()->down();
    FileVersioning::flush();

    replaceWith(File::find($file->id), 'dos');
})->throws(FileVersioningNotMigratedException::class);

it('dispatches FileVersionCreated', function () {
    $fake = EventBusFake::fake();
    $file = versionsUpload('uno');

    replaceWith($file, 'dos');

    $fake->assertDispatched(FileEvent::VersionCreated);
});
