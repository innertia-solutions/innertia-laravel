<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaFilesMigrateUp();
});

afterEach(fn () => innertiaFilesMigrateDown());

it('adds provider columns to files and creates file_imports', function () {
    foreach (['provider', 'link_mode', 'external_id', 'external_url'] as $col) {
        expect(Schema::hasColumn('files', $col))->toBeTrue("falta files.$col");
    }
    expect(Schema::hasTable('file_imports'))->toBeTrue();
    foreach (['kind', 'provider', 'source_items', 'target_directory_id', 'native_format', 'visibility',
              'file_attributes', 'file_id', 'status', 'found', 'imported', 'updated', 'unchanged',
              'skipped', 'failed_count', 'items', 'error', 'trace_id', 'created_by', 'started_at', 'finished_at'] as $col) {
        expect(Schema::hasColumn('file_imports', $col))->toBeTrue("falta file_imports.$col");
    }
});

it('adds provider columns to directories when the table exists', function () {
    innertiaDriveImportMigration()->down();
    Schema::create('directories', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('name');
    });

    innertiaDriveImportMigration()->up();

    expect(Schema::hasColumn('directories', 'provider'))->toBeTrue();
    expect(Schema::hasColumn('directories', 'external_id'))->toBeTrue();
    Schema::drop('directories');
});

it('is idempotent and keeps provider columns of files on down', function () {
    innertiaDriveImportMigration()->up();
    innertiaDriveImportMigration()->down();

    expect(Schema::hasTable('file_imports'))->toBeFalse();
    // files.provider/external_* pueden venir de innertia:providers:install: down no las borra.
    expect(Schema::hasColumn('files', 'external_id'))->toBeTrue();
});

it('app and saas migrations are identical', function () {
    $base = __DIR__ . '/../../../database/migrations';

    expect(file_get_contents("$base/app/2025_01_03_000002_add_drive_import.php"))
        ->toBe(file_get_contents("$base/saas/2025_01_03_000002_add_drive_import.php"));
});
