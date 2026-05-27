<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaFilesMigrateUp();

    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

it('is a no-op when retention is null', function () {
    config()->set('innertia.files.trash_retention_days', null);

    Storage::disk('local')->put('foo.txt', 'hello');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
    ]);
    $file->delete();  // soft
    File::withTrashed()->where('id', $file->id)->update(['deleted_at' => now()->subDays(100)]);

    $this->artisan('innertia:files:purge-trash')
        ->expectsOutputToContain('No retention configured')
        ->assertSuccessful();

    expect(File::withTrashed()->find($file->id))->not->toBeNull();
});

it('purges trashed files past the retention cutoff', function () {
    config()->set('innertia.files.trash_retention_days', 30);

    Storage::disk('local')->put('old.txt', 'old');
    Storage::disk('local')->put('young.txt', 'young');

    $old   = File::create(['disk' => 'local', 'path' => 'old.txt',   'original_name' => 'old.txt']);
    $young = File::create(['disk' => 'local', 'path' => 'young.txt', 'original_name' => 'young.txt']);

    File::withTrashed()->where('id', $old->id)->update(['deleted_at' => now()->subDays(40)]);
    File::withTrashed()->where('id', $young->id)->update(['deleted_at' => now()->subDays(10)]);

    $this->artisan('innertia:files:purge-trash')->assertSuccessful();

    expect(File::withTrashed()->find($old->id))->toBeNull();
    expect(File::withTrashed()->find($young->id))->not->toBeNull();
});

it('--dry-run does not actually delete', function () {
    config()->set('innertia.files.trash_retention_days', 30);

    Storage::disk('local')->put('old.txt', 'old');
    $old = File::create(['disk' => 'local', 'path' => 'old.txt', 'original_name' => 'old.txt']);
    File::withTrashed()->where('id', $old->id)->update(['deleted_at' => now()->subDays(40)]);

    $this->artisan('innertia:files:purge-trash', ['--dry-run' => true])
        ->expectsOutputToContain('Would purge')
        ->assertSuccessful();

    expect(File::withTrashed()->find($old->id))->not->toBeNull();
});
