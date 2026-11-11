<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Models\File;
use Innertia\Files\Support\Checksum;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../helpers/versions.php';
    innertiaFilesMigrateUp();
    Storage::fake('local');
});

afterEach(fn () => innertiaFilesMigrateDown());

it('stores checksum and version 1 on upload', function () {
    $file = versionsUpload('hola');

    expect($file->version)->toBe(1);
    expect($file->checksum)->toBe(hash('sha256', 'hola'));
    expect($file->version_source)->toBe(FileVersionSource::Upload->value);
    expect($file->version_created_at)->not->toBeNull();
});

it('computes checksum from storage when a File row is created directly', function () {
    Storage::disk('local')->put('docs/x.txt', 'directo');

    $file = File::create(['disk' => 'local', 'path' => 'docs/x.txt', 'original_name' => 'x.txt', 'size' => 7]);

    expect($file->checksum)->toBe(hash('sha256', 'directo'));
});

it('leaves checksum null when bytes are not in storage', function () {
    $file = File::create(['disk' => 'local', 'path' => 'missing.txt', 'original_name' => 'm.txt', 'size' => 0]);

    expect($file->checksum)->toBeNull();
    expect($file->version)->toBe(1);
});

it('hashes local and stored contents identically', function () {
    $tmp = versionsTmpFile('abc');
    Storage::disk('local')->put('abc.txt', 'abc');

    expect(Checksum::ofLocal($tmp))->toBe(Checksum::ofStored('local', 'abc.txt'));
    expect(Checksum::ofStored('local', 'nope.txt'))->toBeNull();
});

it('has an empty versions relation for a new file', function () {
    expect(versionsUpload()->versions()->count())->toBe(0);
});
