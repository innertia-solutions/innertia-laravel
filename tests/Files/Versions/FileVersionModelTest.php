<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Models\File;
use Innertia\Files\Support\Checksum;
use Innertia\Files\Support\FileVersioning;

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

it('does not bake an order into the versions relation', function () {
    expect((new File)->versions()->toBase()->orders)->toBeEmpty();
});

it('never fails creating a File when the disk cannot be read', function () {
    $fs = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $fs->shouldReceive('exists')->andReturn(true);
    $fs->shouldReceive('readStream')->andThrow(new \RuntimeException('boom'));
    Storage::set('broken', $fs);

    $file = File::create(['disk' => 'broken', 'path' => 'a.txt', 'original_name' => 'a.txt', 'size' => 1]);

    expect($file->exists)->toBeTrue();
    expect($file->checksum)->toBeNull();
    expect($file->version)->toBe(1);
});

it('does not overwrite explicit versioning attributes', function () {
    Storage::disk('local')->put('e.txt', 'x');

    $file = File::create([
        'disk' => 'local', 'path' => 'e.txt', 'original_name' => 'e.txt', 'size' => 1,
        'checksum' => 'abc', 'version' => 7, 'version_source' => FileVersionSource::Replace->value,
    ]);

    expect($file->checksum)->toBe('abc');
    expect($file->version)->toBe(7);
    expect($file->version_source)->toBe('replace');
});

it('works when the versioning migration has not been applied', function () {
    innertiaFileVersionsMigration()->down();
    FileVersioning::flush();

    $file = versionsUpload('x');
    expect($file->exists)->toBeTrue();
    expect($file->getAttributes())->not->toHaveKey('checksum');

    $direct = File::create(['disk' => 'local', 'path' => 'z.txt', 'original_name' => 'z.txt', 'size' => 0]);
    expect($direct->exists)->toBeTrue();
    expect($direct->getAttributes())->not->toHaveKey('version');
});

it('hashes large local and stored contents identically', function () {
    $payload = str_repeat('a', 70000);
    $tmp = versionsTmpFile($payload);
    Storage::disk('local')->put('big.txt', $payload);

    expect(Checksum::ofLocal($tmp))->toBe(hash('sha256', $payload));
    expect(Checksum::ofStored('local', 'big.txt'))->toBe(hash('sha256', $payload));
});
