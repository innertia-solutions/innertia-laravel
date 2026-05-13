<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->string('url');
            $table->string('description')->nullable();
            $table->json('events')->default('["*"]');
            $table->string('secret');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event_key');
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('webhooks');
    }
};
