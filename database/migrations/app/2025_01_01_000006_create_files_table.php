<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Archivos gestionados por el sistema.
 *
 *   files — Metadatos de archivos subidos (disco, ruta, visibilidad, propietario).
 *           El archivo físico vive en el disco configurado; esta tabla solo guarda metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes

            // Control de acceso
            $table->enum('visibility', ['public', 'auth', 'restricted'])->default('auth');

            // Propietario polimórfico (User, Process, Invoice, etc.)
            // UUIDs: los modelos del dominio usan uuid como PK
            $table->nullableUuidMorphs('owner');

            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->uuid('trash_group_id')->nullable()->index();
            $table->softDeletes();

            $table->index('visibility');
            $table->index('created_by');
            $table->index('mime_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
