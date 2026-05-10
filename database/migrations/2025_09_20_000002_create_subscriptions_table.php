<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            // The User subscribing
            $table->string('subscriber_id')->index();

            // The entity being subscribed to (polymorphic)
            $table->string('subscribable_type');
            $table->string('subscribable_id');

            // ['*'] to receive all events, or specific keys: ['order.shipped', 'invoice.paid']
            $table->json('events')->default('["*"]');

            // Preferred delivery channels: ['mail', 'realtime']
            $table->json('channels')->default('["mail"]');

            $table->timestamps();

            $table->unique(['subscriber_id', 'subscribable_type', 'subscribable_id'], 'subscriptions_unique');
            $table->index(['subscribable_type', 'subscribable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
