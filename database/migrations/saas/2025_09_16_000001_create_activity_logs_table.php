<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('action'); // login, download, access, error, etc.
            $table->string('entity_type')->nullable(); // User, Report, etc.
            $table->string('entity_id')->nullable();   // ID de la entidad
            $table->foreignUuid('user_id')->nullable(); // usuario que realizó la acción
            $table->string('trace_id')->nullable();
            $table->json('metadata')->nullable(); // IP, user_agent, detalles extra
            $table->text('description')->nullable();
            $table->timestamp('created_at');

            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['trace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
