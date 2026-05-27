<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\RestoreDirectory;
use Innertia\Files\Directories\UseCases\TrashDirectory;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\TrashFile;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');

    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../../Files/Directories/helpers/migrate.php';
    innertiaFilesMigrateUp();
    innertiaDirectoriesMigrateUp();

    Schema::table('files', function (Blueprint $table) {
        if (! Schema::hasColumn('files', 'directory_id')) {
            $table->uuid('directory_id')->nullable()->index()->after('owner_id');
        }
    });

    Storage::fake('local');
});

afterEach(function () {
    innertiaDirectoriesMigrateDown();
    innertiaFilesMigrateDown();
});

it('trashes files when their directory is trashed (same group)', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);

    (new TrashDirectory($dir))->execute();

    $trashedDir  = Directory::withTrashed()->find($dir->id);
    $trashedFile = File::withTrashed()->find($file->id);

    expect($trashedFile->deleted_at)->not->toBeNull();
    expect($trashedFile->trash_group_id)->toBe($trashedDir->trash_group_id);
});

it('cascades to files in deeply nested directories', function () {
    $root = Directory::createIn(null, 'root');
    $sub  = Directory::createIn($root, 'sub');
    $deep = Directory::createIn($sub, 'deep');

    $file = File::create([
        'disk' => 'local', 'path' => 'deep.txt', 'original_name' => 'deep.txt',
        'directory_id' => $deep->id,
    ]);

    (new TrashDirectory($root))->execute();

    expect(File::withTrashed()->find($file->id)->deleted_at)->not->toBeNull();
});

it('does not re-group files trashed before the directory', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);

    // Trash the file individually first
    (new TrashFile($file))->execute();
    $firstGroup = File::withTrashed()->find($file->id)->trash_group_id;

    // Then trash the directory
    (new TrashDirectory($dir))->execute();

    // File should keep its own group (the cascade UPDATE filtered out already-trashed files)
    expect(File::withTrashed()->find($file->id)->trash_group_id)->toBe($firstGroup);
});

it('restores files of the same group when directory is restored', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);

    (new TrashDirectory($dir))->execute();

    $trashedDir = Directory::withTrashed()->find($dir->id);
    (new RestoreDirectory($trashedDir))->execute();

    expect(File::find($file->id))->not->toBeNull();
    expect(File::find($file->id)->deleted_at)->toBeNull();
});

it('does not restore files trashed in a separate group', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);

    // Trash file individually (group A)
    (new TrashFile($file))->execute();

    // Trash directory (group B — file already trashed, NOT cascaded)
    (new TrashDirectory($dir))->execute();

    // Restore directory (group B) — file (group A) stays trashed
    $trashedDir = Directory::withTrashed()->find($dir->id);
    (new RestoreDirectory($trashedDir))->execute();

    expect(File::find($file->id))->toBeNull();
    expect(File::withTrashed()->find($file->id))->not->toBeNull();
});
