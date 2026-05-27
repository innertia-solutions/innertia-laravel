<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Events\FileEvent;
use Innertia\Files\Exceptions\OrphanedFileRestoreException;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\RestoreFile;
use Innertia\Files\UseCases\TrashFile;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaFilesMigrateUp();

    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

it('trashes a file with new groupId and event', function () {
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);
    $fake = EventBusFake::fake();

    (new TrashFile($file))->execute();

    $trashed = File::withTrashed()->find($file->id);
    expect($trashed->deleted_at)->not->toBeNull();
    expect($trashed->trash_group_id)->not->toBeNull();

    $fake->assertDispatched(FileEvent::Trashed, fn ($e) => $e->trashGroupId === $trashed->trash_group_id);
});

it('trashes with inherited groupId when cascaded from Directory', function () {
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);
    $groupId = (string) \Illuminate\Support\Str::uuid();

    (new TrashFile($file, groupId: $groupId))->execute();

    expect(File::withTrashed()->find($file->id)->trash_group_id)->toBe($groupId);
});

it('storage file remains intact after trash', function () {
    Storage::disk('local')->put('foo.txt', 'hello');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);

    (new TrashFile($file))->execute();

    expect(Storage::disk('local')->exists('foo.txt'))->toBeTrue();
});

it('restore clears deleted_at and group_id, dispatches event', function () {
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);
    (new TrashFile($file))->execute();

    $fake = EventBusFake::fake();

    $trashed = File::withTrashed()->find($file->id);
    (new RestoreFile($trashed))->execute();

    $restored = File::find($file->id);
    expect($restored)->not->toBeNull();
    expect($restored->deleted_at)->toBeNull();
    expect($restored->trash_group_id)->toBeNull();

    $fake->assertDispatched(FileEvent::Restored);
});

it('restore throws if file not in trash', function () {
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);

    expect(fn () => (new RestoreFile($file))->execute())
        ->toThrow(\LogicException::class);
});
