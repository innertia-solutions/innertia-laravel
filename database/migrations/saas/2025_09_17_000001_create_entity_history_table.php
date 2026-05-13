<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('entity_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type'); // User, Employee, etc.
            $table->string('entity_id');   // ID de la entidad
            $table->string('action');      // created, updated, deleted, restored
            $table->json('changes')->nullable(); // Campos que cambiaron
            $table->json('old_values')->nullable(); // Valores anteriores
            $table->json('new_values')->nullable(); // Valores nuevos
            $table->foreignUuid('user_id')->nullable(); // Quien hizo el cambio
            $table->string('ip_address')->nullable();
            $table->text('reason')->nullable(); // Razón del cambio (opcional)
            $table->timestamp('created_at');

            // Índices para consultas eficientes
            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_history');
    }
};
