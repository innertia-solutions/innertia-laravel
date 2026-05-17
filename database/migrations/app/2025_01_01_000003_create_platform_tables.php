<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Tablas de plataforma (auditoría, historial, logs, procesos).
 *
 *   activity_logs  — Registro de acciones del sistema (login, acceso, errores, etc.)
 *   entity_history — Historial de cambios sobre cualquier modelo (campo a campo)
 *   email_logs     — Trazabilidad de emails enviados (estado, excepción)
 *   processes      — Seguimiento de operaciones asíncronas (imports, exports, backups)
 */
return new class extends Migration
{
    public function up(): void
    {
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

            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['trace_id', 'created_at']);
        });

        Schema::create('entity_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('action');         // created, updated, deleted, restored
            $table->json('changes')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('user_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'action']);
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->nullable()->index();
            $table->string('to');
            $table->string('subject');
            $table->string('mailable_class');
            $table->string('status')->default('pending'); // pending, sent, failed
            $table->text('exception')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('processes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');                  // import | export | backup | process
            $table->string('category');              // FQCN e.g. App\Imports\ImportUsers
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'partial'])
                ->default('pending');
            $table->unsignedTinyInteger('progress')->default(0); // 0–100
            $table->json('metadata')->nullable();
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
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('entity_history');
        Schema::dropIfExists('activity_logs');
    }
};
