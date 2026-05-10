<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key')->unique();
            $table->string('name');
            $table->enum('status', ['trial', 'active', 'inactive'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('configs')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index('key');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
