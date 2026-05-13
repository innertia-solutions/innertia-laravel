<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_apps', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('app');
            $table->string('tenant_id')->index();
            $table->timestamps();

            $table->unique(['user_id', 'app', 'tenant_id']);
            $table->index('user_id');
            $table->index('app');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_apps');
    }
};
