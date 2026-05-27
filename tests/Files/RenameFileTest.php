<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Events\FileEvent;
use Innertia\Files\Exceptions\InvalidFileNameException;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\RenameFile;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaFilesMigrateUp();

    Storage::fake('local');
});

afterEach(function () {
    innertiaFilesMigrateDown();
});

function makeFile(string $name = 'original.pdf'): File
{
    Storage::disk('local')->put('files/2026/05/' . $name, 'dummy');

    return File::create([
        'disk'          => 'local',
        'path'          => 'files/2026/05/' . $name,
        'original_name' => $name,
        'mime_type'     => 'application/pdf',
        'size'          => 100,
    ]);
}

it('renames file by updating original_name', function () {
    $file = makeFile('old-name.pdf');

    $renamed = (new RenameFile(file: $file, newName: 'new-name.pdf'))->execute();

    expect($renamed->original_name)->toBe('new-name.pdf');
    expect($renamed->fresh()->original_name)->toBe('new-name.pdf');
});

it('rejects empty name with InvalidFileNameException', function () {
    $file = makeFile();

    expect(fn () => (new RenameFile(file: $file, newName: '   '))->execute())
        ->toThrow(InvalidFileNameException::class);
});

it('rejects name containing forward slash separator', function () {
    $file = makeFile();

    expect(fn () => (new RenameFile(file: $file, newName: 'sub/file.pdf'))->execute())
        ->toThrow(InvalidFileNameException::class);
});

it('rejects name containing backslash separator', function () {
    $file = makeFile();

    expect(fn () => (new RenameFile(file: $file, newName: 'sub\\file.pdf'))->execute())
        ->toThrow(InvalidFileNameException::class);
});

it('dispatches FileRenamed event with oldName and newName', function () {
    $fake = EventBusFake::fake();
    $file = makeFile('before.pdf');

    (new RenameFile(file: $file, newName: 'after.pdf'))->execute();

    $fake->assertDispatched(FileEvent::Renamed);
});
