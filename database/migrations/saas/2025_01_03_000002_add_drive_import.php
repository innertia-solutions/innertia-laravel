<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Importación desde proveedores (Google Drive).
 *
 *   files        — columnas de procedencia (provider, link_mode, external_id, external_url)
 *                  si aún no existen (innertia:providers:install también las crea).
 *   directories  — provider + external_id para deduplicar carpetas importadas (si la tabla existe;
 *                  el stub de innertia:directories:install las trae para instalaciones nuevas).
 *   file_imports — registro de cada proceso de import / refresh.
 *
 * Idempotente. down() borra file_imports y las columnas de directories, pero NO las de files
 * (pueden pertenecer a innertia:providers:install).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('files')) {
            $this->addMissing('files', [
                'provider'     => fn (Blueprint $t) => $t->string('provider')->nullable(),
                'link_mode'    => fn (Blueprint $t) => $t->string('link_mode', 20)->default('local'),
                'external_id'  => fn (Blueprint $t) => $t->string('external_id')->nullable(),
                'external_url' => fn (Blueprint $t) => $t->string('external_url')->nullable(),
            ]);

            if (! Schema::hasIndex('files', 'files_provider_external_idx')) {
                Schema::table('files', fn (Blueprint $t) => $t->index(['provider', 'external_id'], 'files_provider_external_idx'));
            }
        }

        if (Schema::hasTable('directories')) {
            $this->addMissing('directories', [
                'provider'    => fn (Blueprint $t) => $t->string('provider')->nullable(),
                'external_id' => fn (Blueprint $t) => $t->string('external_id')->nullable(),
            ]);

            if (! Schema::hasIndex('directories', 'directories_provider_external_idx')) {
                Schema::table('directories', fn (Blueprint $t) => $t->index(['provider', 'external_id'], 'directories_provider_external_idx'));
            }
        }

        if (! Schema::hasTable('file_imports')) {
            Schema::create('file_imports', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('kind', 20);                    // import | refresh
                $table->string('provider', 40);
                $table->json('source_items');
                $table->uuid('target_directory_id')->nullable();
                $table->string('native_format', 10)->default('office');
                $table->string('visibility', 20)->default('auth');
                $table->json('file_attributes')->nullable();   // owner_type / owner_id del producto
                $table->uuid('file_id')->nullable()->index(); // solo refresh

                $table->string('status', 20)->default('pending')->index();
                $table->unsignedInteger('found')->default(0);
                $table->unsignedInteger('imported')->default(0);
                $table->unsignedInteger('updated')->default(0);
                $table->unsignedInteger('unchanged')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->json('items')->nullable();
                $table->text('error')->nullable();

                $table->string('trace_id')->nullable();
                $table->string('created_by')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_imports');

        if (Schema::hasTable('directories')) {
            if (Schema::hasIndex('directories', 'directories_provider_external_idx')) {
                Schema::table('directories', fn (Blueprint $t) => $t->dropIndex('directories_provider_external_idx'));
            }
            foreach (['provider', 'external_id'] as $col) {
                if (Schema::hasColumn('directories', $col)) {
                    Schema::table('directories', fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }

    /** @param array<string, \Closure(Blueprint): mixed> $columns */
    private function addMissing(string $table, array $columns): void
    {
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $define($t));
            }
        }
    }
};
