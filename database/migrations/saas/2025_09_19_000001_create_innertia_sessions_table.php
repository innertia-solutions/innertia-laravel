<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->string('user_id')->index();
            $table->string('token_hash')->unique();
            $table->string('device_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('browser')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
