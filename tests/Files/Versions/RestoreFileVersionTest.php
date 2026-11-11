<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Events\FileEvent;
use Innertia\Files\Exceptions\FileTrashedException;
use Innertia\Files\Exceptions\FileVersionNotFoundException;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\UseCases\ReplaceFileContents;
use Innertia\Files\UseCases\RestoreFileVersion;
use Innertia\Files\UseCases\TrashFile;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

it('restores v1 as a new version 3 without touching history', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();

    $result = (new RestoreFileVersion($file, 1))->execute();

    expect($result->wasCreated())->toBeTrue();
    expect($result->file->version)->toBe(3);
    expect($result->file->version_source)->toBe('restore');
    expect($result->file->restored_from_version)->toBe(1);
    expect(Storage::disk('local')->get($result->file->path))->toBe('uno');
    expect(FileVersion::where('file_id', $file->id)->orderBy('version')->pluck('version')->all())->toBe([1, 2]);

    // los bytes de v1 siguen siendo de v1 (la restauración usa una copia)
    $v1 = FileVersion::where('file_id', $file->id)->where('version', 1)->sole();
    expect($v1->path)->not->toBe($result->file->path);
});

it('returns unchanged when restoring the current version number', function () {
    $file = versionsUpload('uno');

    expect((new RestoreFileVersion($file, 1))->execute()->status)->toBe('unchanged');
});

it('returns unchanged when the old version has the same bytes as the current one', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    (new ReplaceFileContents($file, versionsTmpFile('uno'), FileVersionSource::Replace))->execute(); // v3 = uno

    expect((new RestoreFileVersion($file, 1))->execute()->status)->toBe('unchanged');
});

it('throws for a version that does not exist', function () {
    (new RestoreFileVersion(versionsUpload('uno'), 7))->execute();
})->throws(FileVersionNotFoundException::class);

it('dispatches FileVersionRestored', function () {
    $fake = EventBusFake::fake();
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();

    (new RestoreFileVersion($file, 1))->execute();

    $fake->assertDispatched(FileEvent::VersionRestored);
});

it('fails clearly when the bytes of the old version are missing', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    $v1 = FileVersion::where('file_id', $file->id)->where('version', 1)->sole();
    Storage::disk('local')->delete($v1->path);

    (new RestoreFileVersion($file, 1))->execute();
})->throws(\RuntimeException::class, 'no existen en storage');

it('throws for a trashed file and writes nothing', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    $before = Storage::disk('local')->allFiles();
    (new TrashFile($file))->execute();

    try {
        (new RestoreFileVersion($file, 1))->execute();
        $this->fail('Expected FileTrashedException');
    } catch (FileTrashedException) {
        expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing($before);
    }
});

it('normalizes a legacy extension when restoring (temp name and new path)', function () {
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    FileVersion::where('file_id', $file->id)->where('version', 1)->update(['extension' => '.T/x T']);

    $result = (new RestoreFileVersion($file->fresh(), 1))->execute();

    expect($result->wasCreated())->toBeTrue();
    expect($result->file->extension)->toBe('txt');
    expect($result->file->path)->toEndWith('.txt');
    expect(Storage::disk('local')->get($result->file->path))->toBe('uno');
});

it('fails clearly when versioning is not migrated', function () {
    $file = versionsUpload('uno');
    innertiaFileVersionsMigration()->down();
    \Innertia\Files\Support\FileVersioning::flush();

    (new RestoreFileVersion(\Innertia\Files\Models\File::find($file->id), 1))->execute();
})->throws(\Innertia\Files\Exceptions\FileVersioningNotMigratedException::class);
