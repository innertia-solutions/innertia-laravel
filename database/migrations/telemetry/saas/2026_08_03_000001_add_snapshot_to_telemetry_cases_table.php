<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telemetry_cases') && ! Schema::hasColumn('telemetry_cases', 'snapshot')) {
            Schema::table('telemetry_cases', function (Blueprint $table) {
                $table->json('snapshot')->nullable()->after('sample_trace_ids');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('telemetry_cases', 'snapshot')) {
            Schema::table('telemetry_cases', function (Blueprint $table) {
                $table->dropColumn('snapshot');
            });
        }
    }
};
