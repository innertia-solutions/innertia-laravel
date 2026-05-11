<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only relevant in saas mode — skipped by InnertiaServiceProvider in app mode
        Schema::create('tenant_apps', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'app_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_apps');
    }
};
