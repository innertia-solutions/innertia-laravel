<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function innertiaBackupsMigrateUp(): void
{
    // activity_logs is required by the Auditable trait (ActivityLogger::log)
    if (!Schema::hasTable('activity_logs')) {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('user_id')->nullable();
            $table->string('trace_id')->nullable();
            $table->json('metadata')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at');
        });
    }

    Schema::create('backups', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('status')->default('pending'); // pending|running|completed|failed
        $table->string('disk');
        $table->string('path')->nullable();
        $table->unsignedBigInteger('size_bytes')->nullable();
        $table->string('checksum')->nullable();
        $table->string('driver');
        $table->string('mode');
        $table->boolean('is_safety')->default(false);
        $table->text('error')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
        $table->index('status');
        $table->index('is_safety');
    });
}

function innertiaBackupsMigrateDown(): void
{
    Schema::dropIfExists('backups');
    Schema::dropIfExists('activity_logs');
}
