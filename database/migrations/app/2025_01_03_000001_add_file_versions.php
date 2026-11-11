<?php
// database/migrations/app/2025_01_03_000001_add_file_versions.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Versionamiento de contenido de archivos (siempre activo).
 *
 *   files          — gana los atributos de la versión vigente (número, checksum, origen…).
 *   file_versions  — historial append-only de versiones superadas.
 *
 * Idempotente: segura de re-aplicar y de correr sobre instalaciones existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('files')) {
            return;
        }

        $columns = [
            'version'               => fn (Blueprint $t) => $t->unsignedInteger('version')->default(1),
            'checksum'              => fn (Blueprint $t) => $t->string('checksum', 64)->nullable(),
            'version_source'        => fn (Blueprint $t) => $t->string('version_source', 32)->default('upload'),
            'version_created_by'    => fn (Blueprint $t) => $t->string('version_created_by')->nullable(),
            'version_created_at'    => fn (Blueprint $t) => $t->timestamp('version_created_at')->nullable(),
            'restored_from_version' => fn (Blueprint $t) => $t->unsignedInteger('restored_from_version')->nullable(),
            'external_revision'     => fn (Blueprint $t) => $t->string('external_revision')->nullable(),
            'external_export_mime'  => fn (Blueprint $t) => $t->string('external_export_mime')->nullable(),
            'external_synced_at'    => fn (Blueprint $t) => $t->timestamp('external_synced_at')->nullable(),
        ];

        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('files', $name)) {
                Schema::table('files', fn (Blueprint $table) => $define($table));
            }
        }

        DB::table('files')->whereNull('version_created_at')->update([
            'version_created_at' => DB::raw('created_at'),
            'version_created_by' => DB::raw('created_by'),
        ]);

        if (! Schema::hasTable('file_versions')) {
            Schema::create('file_versions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete();
                $table->unsignedInteger('version');

                $table->string('disk');
                $table->string('path');
                $table->string('original_name');
                $table->string('mime_type')->nullable();
                $table->string('extension', 20)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->string('checksum', 64)->nullable();

                $table->string('source', 32);
                $table->unsignedInteger('restored_from_version')->nullable();
                $table->string('external_revision')->nullable();
                $table->string('external_export_mime')->nullable();

                $table->string('created_by')->nullable();
                $table->timestamp('created_at')->nullable();    // cuándo pasó a vigente
                $table->timestamp('superseded_at')->nullable(); // cuándo dejó de serlo

                $table->unique(['file_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');

        if (! Schema::hasTable('files')) {
            return;
        }

        foreach (['version', 'checksum', 'version_source', 'version_created_by', 'version_created_at',
                  'restored_from_version', 'external_revision', 'external_export_mime', 'external_synced_at'] as $col) {
            if (Schema::hasColumn('files', $col)) {
                Schema::table('files', fn (Blueprint $table) => $table->dropColumn($col));
            }
        }
    }
};
