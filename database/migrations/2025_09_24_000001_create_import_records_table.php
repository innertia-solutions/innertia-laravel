<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Which Importer subclass ran this import
            $table->string('type');

            // Status lifecycle: pending → processing → completed | failed | partial
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'partial'])
                ->default('pending');

            // File location in Storage
            $table->string('disk');
            $table->string('file_path');
            $table->string('original_filename')->nullable();

            // Progress tracking
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            // Per-row errors: [{row: 5, message: "email duplicado"}, ...]
            $table->json('errors')->nullable();

            // Who triggered the import (nullable for background/seeder imports)
            $table->string('created_by')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_by');
            $table->index('type');
        });

        // Subscriptions for ImportRecord (uses morphMany via Subscribable trait)
        // The subscriptions table is already created by the platform migration.
    }

    public function down(): void
    {
        Schema::dropIfExists('import_records');
    }
};
