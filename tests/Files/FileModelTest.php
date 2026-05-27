<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    require_once __DIR__ . '/../Tags/helpers/migrate.php';
    innertiaFilesMigrateUp();
    innertiaTagsMigrateUp();

    Storage::fake('local');
});

afterEach(function () {
    innertiaTagsMigrateDown();
    innertiaFilesMigrateDown();
});

it('soft deletes preserves the row and the storage', function () {
    Storage::disk('local')->put('foo.txt', 'hello');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);

    $file->delete();

    expect(File::count())->toBe(0);
    expect(File::withTrashed()->count())->toBe(1);
    expect(Storage::disk('local')->exists('foo.txt'))->toBeTrue();
});

it('forceDelete removes both storage and row', function () {
    Storage::disk('local')->put('foo.txt', 'hello');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);

    $file->forceDelete();

    expect(File::withTrashed()->count())->toBe(0);
    expect(Storage::disk('local')->exists('foo.txt'))->toBeFalse();
});

it('uses HasTags trait', function () {
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);

    $file->tag('important', 'invoice');

    expect($file->fresh()->tags->pluck('slug')->all())->toContain('important', 'invoice');
});
