<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
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

/** Reemplaza el disk local por uno cuyo writeStream ejecuta $write (los demás métodos son reales). */
function versionsFailingDisk(\Closure $write): FilesystemAdapter
{
    $real = Storage::disk('local');
    $disk = new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter {
        public \Closure $onWrite;

        public function writeStream($path, $resource, array $options = [])
        {
            return ($this->onWrite)($path, $resource);
        }
    };
    $disk->onWrite = fn ($path, $resource) => $write($real, $path, $resource);
    Storage::set('local', $disk);

    return $real;
}

it('leaves storage untouched on a concurrent identical replacement', function () {
    $file = versionsUpload('uno');
    $stale = File::find($file->id);
    replaceWith($file, 'dos');
    $before = Storage::disk('local')->allFiles();

    $result = replaceWith($stale, 'dos');

    expect($result->status)->toBe('unchanged');
    expect(Storage::disk('local')->allFiles())->toBe($before);
});

it('cleans up a partial write and leaves the database untouched when writing throws', function () {
    $file = versionsUpload('uno');
    $before = Storage::disk('local')->allFiles();
    $real = versionsFailingDisk(function ($real, $path) {
        $real->put($path, 'parcial');
        throw new \RuntimeException('disco caído');
    });

    expect(fn () => replaceWith($file, 'dos'))->toThrow(\RuntimeException::class, 'disco caído');

    expect($real->allFiles())->toBe($before);
    expect(File::find($file->id)->version)->toBe(1);
    expect(FileVersion::count())->toBe(0);
});

it('fails and leaves no orphan when the disk reports a failed write', function () {
    $file = versionsUpload('uno');
    $before = Storage::disk('local')->allFiles();
    $real = versionsFailingDisk(fn () => false);

    expect(fn () => replaceWith($file, 'dos'))->toThrow(\RuntimeException::class, 'No se pudo escribir');

    expect($real->allFiles())->toBe($before);
    expect(File::find($file->id)->version)->toBe(1);
});

it('normalizes the extension and guesses the mime when it changes', function () {
    $file = versionsUpload('uno', 'a.txt');

    $result = (new ReplaceFileContents($file, versionsTmpFile('%PDF-1.4 falso'), FileVersionSource::Replace, extension: '.PDF'))->execute();

    expect($result->file->extension)->toBe('pdf');
    expect($result->file->path)->toEndWith('.pdf');
    expect($result->file->mime_type)->toBe('application/pdf');
});

it('inherits the current extension and keeps the mime when none is given', function () {
    $file = versionsUpload('uno', 'a.txt');

    $result = replaceWith($file, 'dos');

    expect($result->file->extension)->toBe('txt');
    expect($result->file->path)->toEndWith('.txt');
    expect($result->file->mime_type)->toBe('text/plain');
});

it('stores a null extension and no path suffix when the extension is empty', function () {
    $file = versionsUpload('uno', 'a.txt');

    $result = (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace, extension: ''))->execute();

    expect($result->file->extension)->toBeNull();
    expect(pathinfo($result->file->path, PATHINFO_EXTENSION))->toBe('');
});

it('ignores unknown meta keys', function () {
    $file = versionsUpload('uno');

    $result = replaceWith($file, 'dos', meta: ['path' => 'hack/x.txt', 'version' => 99]);

    expect($result->file->path)->not->toBe('hack/x.txt');
    expect($result->file->version)->toBe(2);
    expect(File::find($file->id)->version)->toBe(2);
});

it('resets restored_from_version on a normal replace after a restore', function () {
    $file = versionsUpload('uno');
    replaceWith($file, 'dos');
    replaceWith($file, 'uno', FileVersionSource::Restore, ['restored_from_version' => 1]);
    expect($file->restored_from_version)->toBe(1);

    $result = replaceWith($file, 'cuatro');

    expect($result->file->restored_from_version)->toBeNull();
    expect(FileVersion::where('file_id', $file->id)->where('version', 3)->sole()->restored_from_version)->toBe(1);
});

it('records the performer as version_created_by and in the event payload', function () {
    $fake = EventBusFake::fake();
    $file = versionsUpload('uno');

    $result = (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace, performedBy: new GenericUser(['id' => 'u-9'])))->execute();

    expect($result->file->version_created_by)->toBe('u-9');
    $fake->assertDispatched(FileEvent::VersionCreated, fn ($e) => $e->payload()['performed_by'] === 'u-9');
});

it('exposes the expected event payload', function () {
    $fake = EventBusFake::fake();
    $file = versionsUpload('uno');

    replaceWith($file, 'dos');

    $payload = $fake->dispatchedMatching(FileEvent::VersionCreated)[0]->payload();
    expect($payload)->toBe([
        'file_id'           => $file->id,
        'version'           => 2,
        'previous_version'  => 1,
        'source'            => 'replace',
        'checksum'          => hash('sha256', 'dos'),
        'previous_checksum' => hash('sha256', 'uno'),
        'performed_by'      => null,
    ]);
});

it('persists external meta on an unchanged result without a new version', function () {
    $file = versionsUpload('igual', 'a.txt');
    $updatedAt = File::find($file->id)->getRawOriginal('updated_at');
    $this->travel(5)->minutes();

    $result = replaceWith($file, 'igual', FileVersionSource::ProviderRefresh, [
        'external_revision'    => 'rev-2',
        'external_export_mime' => 'application/pdf',
        'external_synced_at'   => now(),
        'original_name'        => 'otro.txt',
    ]);

    expect($result->status)->toBe('unchanged');
    expect($file->external_revision)->toBe('rev-2');
    expect($result->file->external_synced_at)->not->toBeNull();

    $row = File::find($file->id);
    expect($row->version)->toBe(1);
    expect($row->external_revision)->toBe('rev-2');
    expect($row->external_export_mime)->toBe('application/pdf');
    expect($row->external_synced_at)->not->toBeNull();
    expect($row->original_name)->toBe('a.txt');
    expect($row->getRawOriginal('updated_at'))->toBe($updatedAt);
    expect(FileVersion::count())->toBe(0);
});

it('persists external meta when the identical replacement is detected inside the lock', function () {
    $file = versionsUpload('uno');
    $stale = File::find($file->id);
    replaceWith($file, 'dos');

    $result = replaceWith($stale, 'dos', FileVersionSource::ProviderRefresh, ['external_revision' => 'rev-3']);

    expect($result->status)->toBe('unchanged');
    expect($stale->external_revision)->toBe('rev-3');
    expect(File::find($file->id)->external_revision)->toBe('rev-3');
    expect(File::find($file->id)->version)->toBe(2);
});

it('dispatches the event only after the outer transaction commits', function () {
    $fake = EventBusFake::fake();
    $file = versionsUpload('uno');

    DB::transaction(function () use ($file, $fake) {
        replaceWith($file, 'dos');
        $fake->assertNotDispatched(FileEvent::VersionCreated);
    });

    $fake->assertDispatched(FileEvent::VersionCreated);
});

it('does not dispatch the event when the outer transaction rolls back', function () {
    $fake = EventBusFake::fake();
    $file = versionsUpload('uno');

    DB::beginTransaction();
    replaceWith($file, 'dos');
    DB::rollBack();

    $fake->assertNotDispatched(FileEvent::VersionCreated);
    expect(File::find($file->id)->version)->toBe(1);
});
