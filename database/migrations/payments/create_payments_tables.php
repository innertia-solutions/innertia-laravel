<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->nullable()->index();
            $t->string('payer_type')->nullable();
            $t->string('payer_id')->nullable();
            $t->string('billable_type')->nullable();
            $t->string('billable_id')->nullable();
            $t->integer('amount');
            $t->string('currency', 3)->default('CLP');
            $t->enum('status', ['pending', 'awaiting_confirmation', 'paid', 'failed', 'refunded'])->default('pending');
            $t->integer('application_fee')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
            $t->index(['billable_type', 'billable_id']);
            $t->index(['payer_type', 'payer_id']);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->nullable()->index();
            $t->uuid('charge_id');
            $t->string('provider');
            $t->string('confirmed_via')->nullable();
            $t->integer('amount');
            $t->string('external_reference')->nullable();
            $t->string('collection_mode')->nullable();
            $t->decimal('commission_rate', 6, 4)->nullable();
            $t->text('link')->nullable();
            $t->timestamp('link_expires_at')->nullable();
            $t->timestamp('declared_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->foreign('charge_id')->references('id')->on('charges')->cascadeOnDelete();
            $t->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('charges');
    }
};
