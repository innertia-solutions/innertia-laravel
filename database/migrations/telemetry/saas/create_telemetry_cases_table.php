<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_cases', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('title');
            $table->string('exception_class');
            $table->string('file', 1024);
            $table->unsignedInteger('line');
            $table->text('message_normalized');
            $table->string('status')->default('open')->index();
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('first_seen');
            $table->timestamp('last_seen')->index();
            $table->string('last_trace_id')->nullable();
            $table->json('sample_trace_ids')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_cases');
    }
};
