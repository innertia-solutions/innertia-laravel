<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web notification center records.
     * Created by DomainEventRouter when a DomainEvent uses the 'web' channel.
     * Displayed in the frontend notification center.
     * Immutable — no updated_at.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('user_id');                  // recipient
            $table->string('type');                     // event class FQCN
            $table->string('key');                      // event key e.g. 'process.completed'
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('data')->nullable();           // full event payload

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
