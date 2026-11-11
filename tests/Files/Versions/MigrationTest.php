<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Files\Exceptions\FileVersioningNotMigratedException;
use Innertia\Files\Support\FileVersioning;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaFilesMigrateUp();
});

afterEach(fn () => innertiaFilesMigrateDown());

it('adds version columns to files and creates file_versions', function () {
    foreach (['version', 'checksum', 'version_source', 'version_created_by', 'version_created_at',
              'restored_from_version', 'external_revision', 'external_export_mime', 'external_synced_at'] as $col) {
        expect(Schema::hasColumn('files', $col))->toBeTrue("falta files.$col");
    }
    expect(Schema::hasTable('file_versions'))->toBeTrue();
    expect(FileVersioning::ready())->toBeTrue();
});

it('is idempotent when applied twice', function () {
    DB::table('files')->insert([
        'id' => '00000000-0000-0000-0000-000000000002', 'disk' => 'local', 'path' => 'b.txt',
        'original_name' => 'b.txt', 'size' => 1, 'visibility' => 'auth',
        'created_by' => 'u1', 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05',
    ]);
    DB::table('files')->update([
        'version' => 3, 'checksum' => 'abc', 'version_created_at' => '2026-05-05 00:00:00',
    ]);

    innertiaFileVersionsMigration()->up();

    $row = DB::table('files')->first();
    expect((int) $row->version)->toBe(3);
    expect($row->checksum)->toBe('abc');
    expect((string) $row->version_created_at)->toBe('2026-05-05 00:00:00');
    expect(Schema::hasTable('file_versions'))->toBeTrue();
});

it('down removes the table and columns but keeps file rows', function () {
    DB::table('files')->insert([
        'id' => '00000000-0000-0000-0000-000000000003', 'disk' => 'local', 'path' => 'c.txt',
        'original_name' => 'c.txt', 'size' => 1, 'visibility' => 'auth',
        'created_by' => 'u1', 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05',
    ]);

    innertiaFileVersionsMigration()->down();

    expect(Schema::hasTable('file_versions'))->toBeFalse();
    expect(Schema::hasColumn('files', 'version'))->toBeFalse();
    expect(DB::table('files')->count())->toBe(1);
});

it('ensureReady throws when not migrated', function () {
    innertiaFileVersionsMigration()->down();
    FileVersioning::flush();

    expect(fn () => FileVersioning::ensureReady())->toThrow(FileVersioningNotMigratedException::class);
});

it('picks up the migration without restarting (no negative cache)', function () {
    innertiaFileVersionsMigration()->down();
    FileVersioning::flush();
    expect(FileVersioning::ready())->toBeFalse();

    innertiaFileVersionsMigration()->up();

    expect(FileVersioning::ready())->toBeTrue();
});

it('app and saas migrations are identical', function () {
    $dir = __DIR__ . '/../../../database/migrations';
    $file = '2025_01_03_000001_add_file_versions.php';

    expect(file_get_contents("$dir/app/$file"))->toBe(file_get_contents("$dir/saas/$file"));
});

it('backfills version_created_at from created_at', function () {
    innertiaFileVersionsMigration()->down();
    DB::table('files')->insert([
        'id' => '00000000-0000-0000-0000-000000000001', 'disk' => 'local', 'path' => 'a.txt',
        'original_name' => 'a.txt', 'size' => 1, 'visibility' => 'auth',
        'created_by' => 'u1', 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05',
    ]);

    innertiaFileVersionsMigration()->up();

    $row = DB::table('files')->first();
    expect((string) $row->version_created_at)->toBe('2026-01-02 03:04:05');
    expect($row->version_created_by)->toBe('u1');
    expect((int) $row->version)->toBe(1);
});

it('reports not ready when the migration is missing', function () {
    innertiaFileVersionsMigration()->down();
    FileVersioning::flush();

    expect(FileVersioning::ready())->toBeFalse();
});
