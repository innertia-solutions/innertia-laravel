<?php

use Innertia\Files\Directories\Events\DirectoryEvent;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\InvalidNameException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\RenameDirectory;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('renames a directory updating name and name_normalized', function () {
    $dir = Directory::createIn(null, 'Old');

    (new RenameDirectory($dir, 'New Name'))->execute();

    expect($dir->fresh()->name)->toBe('New Name');
    expect($dir->fresh()->name_normalized)->toBe('new name');
});

it('does not change path on rename', function () {
    $dir = Directory::createIn(null, 'Old');
    $oldPath = $dir->path;

    (new RenameDirectory($dir, 'New'))->execute();

    expect($dir->fresh()->path)->toBe($oldPath);
});

it('rejects sibling collision', function () {
    $a = Directory::createIn(null, 'A');
    $b = Directory::createIn(null, 'B');

    expect(fn () => (new RenameDirectory($b, 'a'))->execute())
        ->toThrow(DuplicateDirectoryNameException::class);
});

it('allows rename to same name (case difference) on self', function () {
    $dir = Directory::createIn(null, 'Test');

    // Renaming to "test" (lowercase) should NOT collide with itself
    (new RenameDirectory($dir, 'test'))->execute();

    expect($dir->fresh()->name)->toBe('test');
});

it('rejects invalid name', function () {
    $dir = Directory::createIn(null, 'Test');

    expect(fn () => (new RenameDirectory($dir, ''))->execute())
        ->toThrow(InvalidNameException::class);
});

it('dispatches DirectoryRenamed event with old and new names', function () {
    $dir = Directory::createIn(null, 'Old');
    $fake = EventBusFake::fake();

    (new RenameDirectory($dir, 'New'))->execute();

    $fake->assertDispatched(DirectoryEvent::Renamed, function ($event) {
        return $event->oldName === 'Old' && $event->newName === 'New';
    });
});
