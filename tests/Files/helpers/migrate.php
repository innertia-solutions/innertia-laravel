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
        $table->timestamps();
        $table->uuid('trash_group_id')->nullable()->index();
        $table->softDeletes();
        $table->index('mime_type');
    });
}

function innertiaFilesMigrateDown(): void
{
    Schema::dropIfExists('files');
    Schema::dropIfExists('entity_permissions');
}
