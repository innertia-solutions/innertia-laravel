<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Events\FileEvent;
use Innertia\Files\Exceptions\DirectoriesFeatureDisabledException;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\MoveFile;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');

    // Set up both schemas
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../../Files/Directories/helpers/migrate.php';
    innertiaFilesMigrateUp();
    innertiaDirectoriesMigrateUp();

    // Add directory_id to files if not already present (what DirectoriesInstallCommand does)
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

it('moveTo updates directory_id and dispatches FileMoved', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create(['disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt']);

    $fake = EventBusFake::fake();
    $file->moveTo($dir);

    expect($file->fresh()->directory_id)->toBe($dir->id);
    $fake->assertDispatched(FileEvent::Moved, fn ($e) => $e->newDirectoryId === $dir->id);
});

it('moveToRoot sets directory_id to null', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);

    $file->moveToRoot();

    expect($file->fresh()->directory_id)->toBeNull();
});

it('moveTo throws when DirectoriesFeature disabled', function () {
    config()->set('innertia.directories.enabled', false);

    $dir = Directory::createIn(null, 'Reports');
    $file = File::create(['disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt']);

    expect(fn () => $file->moveTo($dir))
        ->toThrow(DirectoriesFeatureDisabledException::class);
});

it('Directory::files relation returns files in the directory', function () {
    $dir = Directory::createIn(null, 'Reports');
    File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);
    File::create([
        'disk' => 'local', 'path' => 'bar.txt', 'original_name' => 'bar.txt',
        'directory_id' => $dir->id,
    ]);
    File::create([
        'disk' => 'local', 'path' => 'orphan.txt', 'original_name' => 'orphan.txt',
        'directory_id' => null,
    ]);

    expect($dir->files)->toHaveCount(2);
});

it('File::directory relation returns parent directory', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);

    expect($file->directory->id)->toBe($dir->id);
    expect($file->directory->name)->toBe('Reports');
});
