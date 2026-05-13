<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified async process tracking table.
     * Replaces import_records and tenant_export_records.
     * Any queued, long-running operation registers here.
     *
     * Types: import | export | backup | process
     */
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Process classification
            $table->string('type');                 // import | export | backup | process
            $table->string('category');             // FQCN e.g. App\Imports\ImportUsers

            // Lifecycle
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'partial'])
                ->default('pending');
            $table->unsignedTinyInteger('progress')->default(0); // 0-100

            // Type-specific data (rows processed, checksums, errors, tenant_id, etc.)
            $table->json('metadata')->nullable();

            // Who triggered it
            $table->string('triggered_by')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('triggered_by');
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
