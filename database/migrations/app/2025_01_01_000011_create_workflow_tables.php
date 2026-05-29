<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->string('entity_type');
            $table->string('name');
            $table->string('label');
            $table->jsonb('config');
            $table->text('source_yaml')->nullable();
            $table->boolean('is_template')->default(false);
            $table->timestamps();

            $table->unique(['entity_type', 'name']);
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->uuid('definition_id');
            $table->foreign('definition_id')->references('id')->on('workflow_definitions');
            $table->string('workflowable_type');
            $table->string('workflowable_id');
            $table->string('current_step');
            $table->jsonb('context')->default('{}');
            $table->string('status')->default('active');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workflowable_type', 'workflowable_id']);
            $table->index('status');
        });

        Schema::create('workflow_transition_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instance_id');
            $table->foreign('instance_id')->references('id')->on('workflow_instances')->onDelete('cascade');
            $table->string('from_step');
            $table->string('to_step');
            $table->string('performed_by');
            $table->text('notes')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_logs');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_definitions');
    }
};
