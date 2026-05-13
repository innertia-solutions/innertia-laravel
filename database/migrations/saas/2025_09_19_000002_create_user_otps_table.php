<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_otps', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('code', 6);
            $table->string('action');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'action', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_otps');
    }
};
