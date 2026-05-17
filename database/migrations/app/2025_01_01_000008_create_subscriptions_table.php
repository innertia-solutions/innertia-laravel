<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Suscripciones a eventos de entidades.
 *
 *   subscriptions — Un usuario se suscribe a eventos de una entidad específica
 *                   para recibirlos por los canales configurados (mail, realtime).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->string('subscriber_id')->index();

            // Entidad a la que se suscribe (polimórfico)
            $table->string('subscribable_type');
            $table->string('subscribable_id');

            // ['*'] para todos los eventos, o específicos: ['order.shipped', 'invoice.paid']
            $table->json('events')->default('["*"]');

            // Canales de entrega: ['mail', 'realtime']
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
