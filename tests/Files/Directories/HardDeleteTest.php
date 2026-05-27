<?php

use Innertia\Files\Directories\Events\DirectoryEvent;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\HardDeleteDirectory;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('hard deletes a single directory', function () {
    $dir = Directory::createIn(null, 'D');

    (new HardDeleteDirectory($dir))->execute();

    expect(Directory::withTrashed()->find($dir->id))->toBeNull();
});

it('rejects hard delete with descendants when not cascading', function () {
    $parent = Directory::createIn(null, 'P');
    Directory::createIn($parent, 'C');

    expect(fn () => (new HardDeleteDirectory($parent))->execute())
        ->toThrow(\LogicException::class);
});

it('cascades hard delete with cascade=true', function () {
    $parent = Directory::createIn(null, 'P');
    $child  = Directory::createIn($parent, 'C');
    $gc     = Directory::createIn($child, 'GC');

    (new HardDeleteDirectory($parent, cascade: true))->execute();

    expect(Directory::withTrashed()->whereIn('id', [$parent->id, $child->id, $gc->id])->count())->toBe(0);
});

it('dispatches DirectoryHardDeleted event', function () {
    $dir = Directory::createIn(null, 'D');
    $fake = EventBusFake::fake();

    (new HardDeleteDirectory($dir))->execute();

    $fake->assertDispatched(DirectoryEvent::HardDeleted, fn ($e) => $e->directoryId === $dir->id);
});
