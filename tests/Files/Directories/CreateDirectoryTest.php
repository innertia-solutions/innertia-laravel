<?php

use Innertia\Files\Directories\Events\DirectoryEvent;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\InvalidNameException;
use Innertia\Files\Directories\Exceptions\ParentTrashedException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\CreateDirectory;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('creates a root directory with correct path and depth', function () {
    $dir = (new CreateDirectory(parent: null, name: 'Reports'))->execute();

    expect($dir->parent_id)->toBeNull();
    expect($dir->depth)->toBe(1);
    expect($dir->path)->toBe('/' . $dir->id . '/');
    expect($dir->name)->toBe('Reports');
    expect($dir->name_normalized)->toBe('reports');
});

it('creates a child directory with parent path prefix', function () {
    $parent = (new CreateDirectory(null, 'Reports'))->execute();
    $child  = (new CreateDirectory(parent: $parent, name: '2026'))->execute();

    expect($child->parent_id)->toBe($parent->id);
    expect($child->depth)->toBe(2);
    expect($child->path)->toBe($parent->path . $child->id . '/');
});

it('rejects empty name', function () {
    expect(fn () => (new CreateDirectory(null, ''))->execute())
        ->toThrow(InvalidNameException::class);

    expect(fn () => (new CreateDirectory(null, '   '))->execute())
        ->toThrow(InvalidNameException::class);
});

it('rejects names containing / or backslash', function () {
    expect(fn () => (new CreateDirectory(null, 'foo/bar'))->execute())
        ->toThrow(InvalidNameException::class);

    expect(fn () => (new CreateDirectory(null, 'foo\\bar'))->execute())
        ->toThrow(InvalidNameException::class);
});

it('rejects sibling with same name case-insensitive', function () {
    (new CreateDirectory(null, 'Reports'))->execute();

    expect(fn () => (new CreateDirectory(null, 'REPORTS'))->execute())
        ->toThrow(DuplicateDirectoryNameException::class);
});

it('rejects creation under a trashed parent', function () {
    $parent = (new CreateDirectory(null, 'Reports'))->execute();
    $parent->delete();

    expect(fn () => (new CreateDirectory(parent: $parent->fresh(), name: '2026'))->execute())
        ->toThrow(ParentTrashedException::class);
});

it('dispatches DirectoryCreated event', function () {
    $fake = EventBusFake::fake();

    $dir = (new CreateDirectory(null, 'Reports'))->execute();

    $fake->assertDispatched(DirectoryEvent::Created, fn ($e) => $e->directory->id === $dir->id);
});
