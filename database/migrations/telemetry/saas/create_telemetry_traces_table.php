<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_traces', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id')->unique();
            $table->string('session_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('tenant')->nullable()->index();
            $table->string('method', 10)->nullable();
            $table->string('route')->nullable();
            $table->string('url', 1024)->nullable();
            $table->string('view')->nullable();
            $table->unsignedSmallInteger('status')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('payload')->nullable();
            $table->json('response_summary')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['tenant', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_traces');
    }
};
