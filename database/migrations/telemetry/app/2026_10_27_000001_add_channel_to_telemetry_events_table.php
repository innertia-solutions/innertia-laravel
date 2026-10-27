<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade idempotente: agrega `channel` a telemetry_events en instalaciones que
 * crearon la tabla antes de que la columna existiera (la columna se añadió
 * editando el `create`, por lo que las tablas preexistentes no la recibieron).
 * En fresh install es no-op (el create ya la trae).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telemetry_events')) {
            return;
        }
        Schema::table('telemetry_events', function (Blueprint $table) {
            if (! Schema::hasColumn('telemetry_events', 'channel')) {
                $table->string('channel')->nullable()->index()->after('type');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('telemetry_events', 'channel')) {
            Schema::table('telemetry_events', function (Blueprint $table) {
                $table->dropColumn('channel');
            });
        }
    }
};
