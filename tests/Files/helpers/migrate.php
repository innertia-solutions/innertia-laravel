<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function innertiaFilesMigrateUp(): void
{
    Schema::create('entity_permissions', function (Blueprint $table) {
        $table->id();
        $table->string('entity_type');
        $table->string('entity_id');
        $table->string('grantable_type');
        $table->string('grantable_id');
        $table->string('action')->default('access');
        $table->timestamps();
    });

    Schema::create('files', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('disk');
        $table->string('path');
        $table->string('original_name');
        $table->string('mime_type')->nullable();
        $table->string('extension', 20)->nullable();
        $table->unsignedBigInteger('size')->default(0);
        $table->enum('visibility', ['public', 'auth', 'restricted'])->default('auth');
        $table->nullableUuidMorphs('owner');
        $table->string('created_by')->nullable();
        $table->uuid('directory_id')->nullable()->index();
        $table->timestamps();
        $table->uuid('trash_group_id')->nullable()->index();
        $table->softDeletes();
        $table->index('mime_type');
    });

    innertiaFileVersionsMigration()->up();
    innertiaDriveImportMigration()->up();
    \Innertia\Files\Support\FileVersioning::flush();
}

function innertiaFilesMigrateDown(): void
{
    Schema::dropIfExists('file_imports');
    Schema::dropIfExists('file_versions');
    \Innertia\Files\Support\FileVersioning::flush();
    Schema::dropIfExists('files');
    Schema::dropIfExists('entity_permissions');
}

function innertiaFileVersionsMigration(): \Illuminate\Database\Migrations\Migration
{
    return require __DIR__ . '/../../../database/migrations/app/2025_01_03_000001_add_file_versions.php';
}

function innertiaDriveImportMigration(): \Illuminate\Database\Migrations\Migration
{
    return require __DIR__ . '/../../../database/migrations/app/2025_01_03_000002_add_drive_import.php';
}
