<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apps are defined in config('innertia.apps') — no apps table needed.
 * This migration only creates the user ↔ app access pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSaas = config('innertia.mode') === 'saas';

        Schema::create('user_apps', function (Blueprint $table) use ($isSaas) {
            $table->id();
            $table->string('user_id');

            // App key from config('innertia.apps'), e.g. 'backoffice', 'technicians'
            $table->string('app');

            if ($isSaas) {
                $table->string('tenant_id')->index();
            }

            $table->timestamps();

            $isSaas
                ? $table->unique(['user_id', 'app', 'tenant_id'])
                : $table->unique(['user_id', 'app']);

            $table->index('user_id');
            $table->index('app');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_apps');
    }
};
