<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Notificaciones web del usuario.
 *
 *   user_notifications — Bandeja de notificaciones in-app.
 *                        Creadas por DomainEventRouter cuando un evento usa el canal 'web'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('user_id');
            $table->string('type');          // FQCN del evento
            $table->string('key');           // clave legible: 'process.completed'
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('data')->nullable(); // payload completo del evento

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
