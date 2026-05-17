<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');

            $table->string('name');
            $table->string('key')->unique();
            $table->string('key_prefix', 12)->index();
            $table->string('key_hint', 8);
            $table->json('permissions')->default('[]');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->index(['client_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_api_keys');
    }
};
