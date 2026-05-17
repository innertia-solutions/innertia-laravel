<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('product');
            $table->string('tenant');
            $table->string('name');
            $table->string('status')->default('active'); // active | suspended

            $table->json('tags')->default('[]');
            $table->json('options')->default('{}');

            $table->timestamps();

            $table->unique(['product', 'tenant']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
