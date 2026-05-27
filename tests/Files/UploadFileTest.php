<?php

use Illuminate\Http\Testing\File as TestFile;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Events\FileEvent;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\UploadFile;
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

it('creates file from UploadedFile with metadata', function () {
    $uploaded = TestFile::create('report.pdf', 100, 'application/pdf');

    $file = (new UploadFile(uploaded: $uploaded))->execute();

    expect($file)->toBeInstanceOf(File::class);
    expect($file->original_name)->toBe('report.pdf');
    expect($file->mime_type)->toBe('application/pdf');
    expect($file->size)->toBeGreaterThan(0);
    expect($file->disk)->toBe('local');
    expect($file->path)->not->toBeEmpty();
    expect(File::count())->toBe(1);
});

it('stores binary to fake disk', function () {
    $uploaded = TestFile::create('document.txt', 50, 'text/plain');

    $file = (new UploadFile(uploaded: $uploaded))->execute();

    Storage::disk('local')->assertExists($file->path);
});

it('dispatches FileUploaded event', function () {
    $fake = EventBusFake::fake();
    $uploaded = TestFile::create('image.png', 200, 'image/png');

    (new UploadFile(uploaded: $uploaded))->execute();

    $fake->assertDispatched(FileEvent::Uploaded);
});
