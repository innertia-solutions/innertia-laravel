<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Definiciones de flujos — tenant_id NULL = template global de Innertia
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index(); // null = template global
            $table->string('entity_type');          // FQCN del modelo
            $table->string('name');                 // identificador interno (ej: 'external_audit')
            $table->string('label');                // nombre legible
            $table->jsonb('config');                // steps + transitions + restrictions (compilado del YAML)
            $table->text('source_yaml')->nullable(); // YAML original para re-exportar
            $table->boolean('is_template')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'name']);
        });

        // Instancias activas — una por entidad
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();     // requerido en saas
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
            $table->index(['tenant_id', 'status']);
        });

        // Log inmutable de transiciones
        Schema::create('workflow_transition_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instance_id');
            $table->foreign('instance_id')->references('id')->on('workflow_instances')->onDelete('cascade');
            $table->string('from_step');
            $table->string('to_step');
            $table->string('performed_by');         // UUID del usuario (string — no FK)
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
