<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // 'backoffice', 'student', 'technician'
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // User ↔ App access (always created)
        $isSaas = config('innertia.mode') === 'saas';

        Schema::create('user_apps', function (Blueprint $table) use ($isSaas) {
            $table->id();
            $table->string('user_id');
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            if ($isSaas) {
                $table->string('tenant_id')->index();
            }
            $table->timestamps();

            $isSaas
                ? $table->unique(['user_id', 'app_id', 'tenant_id'])
                : $table->unique(['user_id', 'app_id']);

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_apps');
        Schema::dropIfExists('apps');
    }
};
