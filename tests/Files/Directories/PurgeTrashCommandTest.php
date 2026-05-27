<?php

use Innertia\Files\Directories\Models\Directory;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('is a no-op when retention is null', function () {
    config()->set('innertia.directories.trash_retention_days', null);

    $dir = Directory::createIn(null, 'D');
    $dir->delete();  // soft delete
    Directory::withTrashed()->where('id', $dir->id)->update(['deleted_at' => now()->subDays(100)]);

    $this->artisan('innertia:directories:purge-trash')
        ->expectsOutputToContain('No retention configured')
        ->assertSuccessful();

    expect(Directory::withTrashed()->find($dir->id))->not->toBeNull();
});

it('purges trashed directories past the retention cutoff', function () {
    config()->set('innertia.directories.trash_retention_days', 30);

    $old = Directory::createIn(null, 'old');
    $young = Directory::createIn(null, 'young');

    Directory::withTrashed()->where('id', $old->id)->update([
        'deleted_at' => now()->subDays(40),
    ]);
    Directory::withTrashed()->where('id', $young->id)->update([
        'deleted_at' => now()->subDays(10),
    ]);

    $this->artisan('innertia:directories:purge-trash')->assertSuccessful();

    expect(Directory::withTrashed()->find($old->id))->toBeNull();
    expect(Directory::withTrashed()->find($young->id))->not->toBeNull();
});

it('--dry-run does not actually delete', function () {
    config()->set('innertia.directories.trash_retention_days', 30);

    $old = Directory::createIn(null, 'old');
    Directory::withTrashed()->where('id', $old->id)->update([
        'deleted_at' => now()->subDays(40),
    ]);

    $this->artisan('innertia:directories:purge-trash', ['--dry-run' => true])
        ->expectsOutputToContain('Would purge')
        ->assertSuccessful();

    expect(Directory::withTrashed()->find($old->id))->not->toBeNull();
});
