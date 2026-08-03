<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telemetry_cases')) return;
        Schema::table('telemetry_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('telemetry_cases', 'origin')) {
                $table->string('origin', 20)->nullable()->index()->after('status');
            }
            if (! Schema::hasColumn('telemetry_cases', 'category')) {
                $table->string('category', 20)->nullable()->index()->after('origin');
            }
            if (! Schema::hasColumn('telemetry_cases', 'tags')) {
                $table->json('tags')->nullable()->after('snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('telemetry_cases', function (Blueprint $table) {
            foreach (['origin', 'category', 'tags'] as $col) {
                if (Schema::hasColumn('telemetry_cases', $col)) $table->dropColumn($col);
            }
        });
    }
};
