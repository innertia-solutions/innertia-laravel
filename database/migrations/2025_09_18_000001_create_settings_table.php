<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSaas = config('innertia.mode') === 'saas';

        Schema::create('settings', function (Blueprint $table) use ($isSaas) {
            $table->id();
            if ($isSaas) {
                $table->string('tenant_id')->nullable()->index();
                $table->unique(['tenant_id', 'key']);
            } else {
                $table->unique('key');
            }
            $table->string('key');
            $table->string('value_type')->default('string');
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
