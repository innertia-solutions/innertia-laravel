<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — API Keys (single-app mode)
 *
 * Two types distinguished by prefix:
 *   inn_a_ → App key   — no user context (server-to-server)
 *   inn_u_ → User key  — scoped to a specific user
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->nullable()->index(); // null = app-level key
            $table->string('name');
            $table->string('type')->default('app');         // 'app' | 'user'
            $table->string('key')->unique();                // hashed
            $table->string('key_hint', 8);
            $table->json('permissions')->default('[]');
            $table->uuid('created_by_key_id')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
