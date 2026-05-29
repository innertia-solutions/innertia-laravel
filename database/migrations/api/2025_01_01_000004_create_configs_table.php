<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Tabla polimórfica de configuraciones.
 *
 * Centraliza preferencias, settings, módulos e integraciones de cualquier entidad
 * (User, Tenant, Integration, etc.) en una sola tabla tipada con control de privacidad.
 *
 *   owner_type / owner_id  — polimórfico: cualquier modelo
 *   type                   — 'preference' | 'setting' | 'module' | 'integration'
 *   key                    — identificador dentro del type
 *   value                  — JSON
 *   cast                   — cómo deserializar value: string, boolean, integer, json, encrypted
 *   privacy                — 'public' (expuesto al frontend) | 'private' (solo backend)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configs', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type');
            $table->string('owner_id');

            $table->string('type');        // preference | setting | module | integration
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('cast')->default('string'); // string | boolean | integer | json | encrypted
            $table->string('privacy')->default('private'); // public | private

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'type', 'key']);
            $table->index(['owner_type', 'owner_id', 'privacy']);
            $table->index(['owner_type', 'owner_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configs');
    }
};
