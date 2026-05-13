<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes

            // Access control
            $table->enum('visibility', ['public', 'auth', 'restricted'])->default('auth');

            // Polymorphic owner (User, Process, Invoice, etc.)
            $table->nullableMorphs('owner');

            // Who uploaded it
            $table->string('created_by')->nullable();

            $table->timestamps();

            $table->index('visibility');
            $table->index('created_by');
            $table->index('mime_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
