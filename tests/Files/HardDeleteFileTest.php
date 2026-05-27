<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Events\FileEvent;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\HardDeleteFile;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaFilesMigrateUp();

    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

it('hard deletes file and removes storage', function () {
    Storage::disk('local')->put('foo.txt', 'hello');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);

    (new HardDeleteFile($file))->execute();

    expect(File::withTrashed()->find($file->id))->toBeNull();
    expect(Storage::disk('local')->exists('foo.txt'))->toBeFalse();
});

it('dispatches FileHardDeleted event', function () {
    Storage::disk('local')->put('foo.txt', 'hello');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);
    $fake = EventBusFake::fake();

    (new HardDeleteFile($file))->execute();

    $fake->assertDispatched(FileEvent::HardDeleted, fn ($e) => $e->fileId === $file->id);
});
