<?php

use Innertia\Files\Directories\Events\DirectoryEvent;
use Innertia\Files\Directories\Exceptions\OrphanedRestoreException;
use Innertia\Files\Directories\Exceptions\RestoreCollisionException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\RestoreDirectory;
use Innertia\Files\Directories\UseCases\TrashDirectory;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('trashes a directory and all descendants with same group id', function () {
    $root = Directory::createIn(null, 'root');
    $a    = Directory::createIn($root, 'a');
    $b    = Directory::createIn($a, 'b');

    (new TrashDirectory($root))->execute();

    $rootFresh = Directory::withTrashed()->find($root->id);
    $aFresh    = Directory::withTrashed()->find($a->id);
    $bFresh    = Directory::withTrashed()->find($b->id);

    expect($rootFresh->deleted_at)->not->toBeNull();
    expect($aFresh->deleted_at)->not->toBeNull();
    expect($bFresh->deleted_at)->not->toBeNull();

    expect($rootFresh->trash_group_id)->toBe($aFresh->trash_group_id);
    expect($aFresh->trash_group_id)->toBe($bFresh->trash_group_id);
});

it('restore brings back only same group, not previously trashed descendants', function () {
    $root = Directory::createIn(null, 'root');
    $a    = Directory::createIn($root, 'a');
    $b    = Directory::createIn($a, 'b');

    // Trash b first (creates group G1)
    (new TrashDirectory($b))->execute();
    $g1 = Directory::withTrashed()->find($b->id)->trash_group_id;

    // Then trash root (creates group G2; b should NOT be touched because already trashed)
    (new TrashDirectory($root))->execute();

    $bFresh = Directory::withTrashed()->find($b->id);
    expect($bFresh->trash_group_id)->toBe($g1);  // still G1

    // Restore root — restores root and a (G2), but NOT b (G1)
    $rootTrashed = Directory::withTrashed()->find($root->id);
    (new RestoreDirectory($rootTrashed))->execute();

    expect(Directory::find($root->id))->not->toBeNull();
    expect(Directory::find($a->id))->not->toBeNull();
    expect(Directory::find($b->id))->toBeNull();
    expect(Directory::withTrashed()->find($b->id)->trash_group_id)->toBe($g1);
});

it('dispatches DirectoryTrashed event with group id', function () {
    $dir = Directory::createIn(null, 'D');
    $fake = EventBusFake::fake();

    (new TrashDirectory($dir))->execute();

    $fake->assertDispatched(DirectoryEvent::Trashed, function ($event) use ($dir) {
        return $event->directory->id === $dir->id && $event->trashGroupId !== null;
    });
});

it('dispatches DirectoryRestored event', function () {
    $dir = Directory::createIn(null, 'D');
    (new TrashDirectory($dir))->execute();

    $fake = EventBusFake::fake();

    (new RestoreDirectory(Directory::withTrashed()->find($dir->id)))->execute();

    $fake->assertDispatched(DirectoryEvent::Restored);
});

it('throws OrphanedRestoreException when parent is hard-deleted', function () {
    $parent = Directory::createIn(null, 'P');
    $child  = Directory::createIn($parent, 'C');

    (new TrashDirectory($child))->execute();
    // Now hard-delete the parent — child is orphaned
    Directory::withTrashed()->find($parent->id)->forceDelete();

    $childTrashed = Directory::withTrashed()->find($child->id);

    expect(fn () => (new RestoreDirectory($childTrashed))->execute())
        ->toThrow(OrphanedRestoreException::class);
});

it('allows restore with new parent when original is gone', function () {
    $parent  = Directory::createIn(null, 'P');
    $newHome = Directory::createIn(null, 'NewHome');
    $child   = Directory::createIn($parent, 'C');

    (new TrashDirectory($child))->execute();
    Directory::withTrashed()->find($parent->id)->forceDelete();

    $childTrashed = Directory::withTrashed()->find($child->id);

    (new RestoreDirectory($childTrashed, relocateParent: $newHome))->execute();

    expect(Directory::find($child->id)->parent_id)->toBe($newHome->id);
});

it('throws RestoreCollisionException when restoring conflicts with live sibling', function () {
    $a = Directory::createIn(null, 'A');
    (new TrashDirectory($a))->execute();

    // Create a new "A" while original is trashed
    Directory::createIn(null, 'A');

    $aTrashed = Directory::withTrashed()->find($a->id);

    expect(fn () => (new RestoreDirectory($aTrashed))->execute())
        ->toThrow(RestoreCollisionException::class);
});
