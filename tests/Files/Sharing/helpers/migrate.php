<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function innertiaShareMigrateUp(): void
{
    Schema::create('entity_permissions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('tenant_id')->nullable()->index();
        $table->string('entity_type');
        $table->string('entity_id');
        $table->string('grantable_type');
        $table->string('grantable_id');
        $table->string('action')->default('access');
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('directories', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('tenant_id')->nullable()->index();
        $table->uuid('parent_id')->nullable()->index();
        $table->string('path', 4096);
        $table->unsignedSmallInteger('depth')->default(0);
        $table->string('name');
        $table->string('name_normalized', 255);
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->uuid('created_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->uuid('trash_group_id')->nullable()->index();
        $table->index('path');
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
    });
}

function innertiaShareMigrateDown(): void
{
    Schema::dropIfExists('files');
    Schema::dropIfExists('directories');
    Schema::dropIfExists('entity_permissions');
}
