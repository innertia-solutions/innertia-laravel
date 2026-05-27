<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function innertiaDirectoriesMigrateUp(): void
{
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
        $table->unique(
            ['tenant_id', 'owner_type', 'owner_id', 'parent_id', 'name_normalized'],
            'directories_name_unique'
        );
    });
}

function innertiaDirectoriesMigrateDown(): void
{
    Schema::dropIfExists('directories');
}
