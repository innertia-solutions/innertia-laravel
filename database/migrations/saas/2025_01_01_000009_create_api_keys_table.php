<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — API Keys
 *
 * Two types distinguished by prefix (stored in key_prefix):
 *   inn_t_ → TenantApiKey  — identifies tenant only
 *   inn_u_ → UserApiKey    — identifies tenant + user
 *
 * The `key` column stores a bcrypt/hash of the raw key — never the raw value.
 * key_hint stores the last 4 chars for display (e.g. "...a3f2").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->index();
            $table->string('user_id')->nullable()->index(); // null = tenant key
            $table->string('name');                         // human label
            $table->string('type')->default('tenant');      // 'tenant' | 'user'
            $table->string('key')->unique();                // hashed raw key
            $table->string('key_hint', 8);                 // last 4 chars for display
            $table->json('permissions')->default('[]');     // granted permissions subset
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
            $table->index(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
