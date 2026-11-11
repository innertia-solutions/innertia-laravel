<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\UseCases\ReplaceFileContents;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

function bumpTo(int $versions): \Innertia\Files\Models\File
{
    $file = versionsUpload('c1');
    for ($i = 2; $i <= $versions; $i++) {
        (new ReplaceFileContents($file, versionsTmpFile("c{$i}"), FileVersionSource::Replace))->execute();
    }

    return $file->fresh();
}

it('keeps every version by default', function () {
    config()->set('innertia.files.versions.keep', null);

    $file = bumpTo(5);

    expect(FileVersion::where('file_id', $file->id)->count())->toBe(4);
});

it('keeps only the N most recent superseded versions and deletes their bytes', function () {
    config()->set('innertia.files.versions.keep', 2);

    $file = bumpTo(5); // vigente v5; historial v1..v4 → quedan v3, v4

    $kept = FileVersion::where('file_id', $file->id)->orderBy('version')->get();
    expect($kept->pluck('version')->all())->toBe([3, 4]);

    $paths = collect(Storage::disk('local')->allFiles());
    expect($paths)->toHaveCount(3); // v3, v4 y la vigente
    foreach ($kept as $v) {
        Storage::disk('local')->assertExists($v->path);
    }
    Storage::disk('local')->assertExists($file->path);
});

it('treats keep=0 as no history', function () {
    config()->set('innertia.files.versions.keep', 0);

    $file = bumpTo(3);

    expect(FileVersion::where('file_id', $file->id)->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('treats an empty string (env sin valor) as keep all', function () {
    config()->set('innertia.files.versions.keep', '');

    $file = bumpTo(4);

    expect(FileVersion::where('file_id', $file->id)->count())->toBe(3);
});
