<?php

use Innertia\Files\Directories\Models\Directory;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');

    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('uses uuids', function () {
    $dir = Directory::create([
        'name'            => 'Test',
        'name_normalized' => 'test',
        'path'            => '/dummy/',
        'depth'           => 1,
    ]);

    expect($dir->id)->toBeString();
    expect(strlen($dir->id))->toBe(36);
});

it('uses soft deletes', function () {
    $dir = Directory::create([
        'name'            => 'Test',
        'name_normalized' => 'test',
        'path'            => '/dummy/',
        'depth'           => 1,
    ]);

    $dir->delete();

    expect(Directory::count())->toBe(0);
    expect(Directory::withTrashed()->count())->toBe(1);
});
