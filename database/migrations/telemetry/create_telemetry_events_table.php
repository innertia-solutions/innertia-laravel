<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telemetry_events')) {
            Schema::create('telemetry_events', function (Blueprint $table) {
                $table->id();
                $table->string('app')->index();
                $table->string('session_id')->index();
                $table->string('type')->index();
                $table->timestamp('occurred_at')->index();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('payload');
                $table->json('context');
                $table->timestamps();

                $table->index(['app', 'type']);
                $table->index(['app', 'occurred_at']);
                $table->index(['session_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_events');
    }
};
