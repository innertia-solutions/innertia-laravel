<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\UseCases\EmptyFilesTrash;
use Innertia\Files\UseCases\ReplaceFileContents;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

function withThreeVersions(): \Innertia\Files\Models\File
{
    $file = versionsUpload('uno');
    (new ReplaceFileContents($file, versionsTmpFile('dos'), FileVersionSource::Replace))->execute();
    (new ReplaceFileContents($file, versionsTmpFile('tres'), FileVersionSource::Replace))->execute();

    return $file->fresh();
}

it('keeps versions when the file goes to the trash and comes back', function () {
    $file = withThreeVersions();

    $file->trash();
    expect(FileVersion::where('file_id', $file->id)->count())->toBe(2);

    $file->fresh()->restoreFromTrash();
    expect(FileVersion::where('file_id', $file->id)->count())->toBe(2);
    expect(Storage::disk('local')->allFiles())->toHaveCount(3);
});

it('removes the bytes and rows of every version on force delete', function () {
    $file = withThreeVersions();

    $file->forceDelete();

    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(FileVersion::count())->toBe(0);
});

it('removes versions when emptying the trash', function () {
    $file = withThreeVersions();
    $file->trash();

    (new EmptyFilesTrash())->execute();

    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(FileVersion::count())->toBe(0);
});
