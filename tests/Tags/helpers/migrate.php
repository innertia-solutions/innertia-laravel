<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function innertiaTagsMigrateUp(): void
{
    Schema::create('tags', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('tenant_id')->nullable()->index();
        $t->string('name');
        $t->string('slug', 80);
        $t->string('color', 7)->nullable();
        $t->uuid('created_by')->nullable();
        $t->timestamps();
        $t->unique(['tenant_id', 'slug']);
    });

    Schema::create('taggables', function (Blueprint $t) {
        $t->uuid('tag_id');
        $t->string('taggable_type');
        $t->uuid('taggable_id');
        $t->uuid('tagged_by')->nullable();
        $t->timestamp('tagged_at')->useCurrent();
        $t->primary(['tag_id', 'taggable_type', 'taggable_id']);
        $t->index(['taggable_type', 'taggable_id']);
        $t->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
    });
}

function innertiaTagsMigrateDown(): void
{
    Schema::dropIfExists('taggables');
    Schema::dropIfExists('tags');
}
