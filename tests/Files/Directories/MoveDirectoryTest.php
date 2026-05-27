<?php

use Innertia\Files\Directories\Events\DirectoryEvent;
use Innertia\Files\Directories\Exceptions\CircularMoveException;
use Innertia\Files\Directories\Exceptions\CrossOwnerMoveException;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\MaxDepthExceededException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\MoveDirectory;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    config()->set('innertia.directories.max_depth', 20);
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('moves a directory to a new parent, updating path and depth', function () {
    $oldParent = Directory::createIn(null, 'OldParent');
    $newParent = Directory::createIn(null, 'NewParent');
    $dir       = Directory::createIn($oldParent, 'Moving');

    (new MoveDirectory($dir, $newParent))->execute();

    $fresh = $dir->fresh();
    expect($fresh->parent_id)->toBe($newParent->id);
    expect($fresh->depth)->toBe(2);
    expect($fresh->path)->toBe($newParent->path . $dir->id . '/');
});

it('updates descendants paths and depths on move', function () {
    $oldParent = Directory::createIn(null, 'OldP');
    $newParent = Directory::createIn(null, 'NewP');
    $dir       = Directory::createIn($oldParent, 'D');
    $child     = Directory::createIn($dir, 'C');
    $grandchild = Directory::createIn($child, 'GC');

    (new MoveDirectory($dir, $newParent))->execute();

    $freshChild = $child->fresh();
    expect($freshChild->path)->toStartWith($newParent->path);
    expect($freshChild->depth)->toBe(3);

    $freshGC = $grandchild->fresh();
    expect($freshGC->path)->toStartWith($newParent->path);
    expect($freshGC->depth)->toBe(4);
});

it('rejects self-move (circular)', function () {
    $dir = Directory::createIn(null, 'D');

    expect(fn () => (new MoveDirectory($dir, $dir))->execute())
        ->toThrow(CircularMoveException::class);
});

it('rejects move into own descendant', function () {
    $parent = Directory::createIn(null, 'P');
    $child  = Directory::createIn($parent, 'C');

    expect(fn () => (new MoveDirectory($parent, $child))->execute())
        ->toThrow(CircularMoveException::class);
});

it('rejects move that exceeds max depth', function () {
    config()->set('innertia.directories.max_depth', 3);

    $a = Directory::createIn(null, 'a');     // depth 1
    $b = Directory::createIn($a, 'b');       // depth 2
    $c = Directory::createIn($b, 'c');       // depth 3
    $other = Directory::createIn(null, 'other'); // depth 1
    $deep  = Directory::createIn($other, 'deep'); // depth 2

    // Moving 'deep' under c would make it depth 4 > max 3
    expect(fn () => (new MoveDirectory($deep, $c))->execute())
        ->toThrow(MaxDepthExceededException::class);
});

it('rejects move across owners', function () {
    // Simulate with concrete fakes via raw insertion
    Directory::query()->where('id', '!=', '')->delete();
    $a = Directory::create([
        'name' => 'a', 'name_normalized' => 'a', 'path' => '/dummyA/', 'depth' => 1,
        'owner_type' => 'OwnerA', 'owner_id' => 'p1',
    ]);
    $a->path = '/' . $a->id . '/'; $a->save();

    $b = Directory::create([
        'name' => 'b', 'name_normalized' => 'b', 'path' => '/dummyB/', 'depth' => 1,
        'owner_type' => 'OwnerB', 'owner_id' => 'p2',
    ]);
    $b->path = '/' . $b->id . '/'; $b->save();

    expect(fn () => (new MoveDirectory($a, $b))->execute())
        ->toThrow(CrossOwnerMoveException::class);
});

it('rejects move when sibling name collides', function () {
    $a = Directory::createIn(null, 'A');
    $b = Directory::createIn(null, 'B');
    $aChild = Directory::createIn($a, 'duplicate');
    $bChild = Directory::createIn($b, 'duplicate');

    expect(fn () => (new MoveDirectory($aChild, $b))->execute())
        ->toThrow(DuplicateDirectoryNameException::class);
});

it('moveToRoot sets parent_id to null and depth to 1', function () {
    $parent = Directory::createIn(null, 'P');
    $dir    = Directory::createIn($parent, 'D');

    $dir->moveToRoot();

    $fresh = $dir->fresh();
    expect($fresh->parent_id)->toBeNull();
    expect($fresh->depth)->toBe(1);
    expect($fresh->path)->toBe('/' . $dir->id . '/');
});

it('dispatches DirectoryMoved event with old and new parent ids', function () {
    $oldP = Directory::createIn(null, 'old');
    $newP = Directory::createIn(null, 'new');
    $dir  = Directory::createIn($oldP, 'D');

    $fake = EventBusFake::fake();

    (new MoveDirectory($dir, $newP))->execute();

    $fake->assertDispatched(DirectoryEvent::Moved, function ($event) use ($oldP, $newP) {
        return $event->oldParentId === $oldP->id && $event->newParentId === $newP->id;
    });
});
