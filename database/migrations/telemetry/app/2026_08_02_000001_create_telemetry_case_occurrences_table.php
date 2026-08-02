<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_case_occurrences', function (Blueprint $table) {
            $table->id();
            $table->string('case_id');
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['case_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_case_occurrences');
    }
};
