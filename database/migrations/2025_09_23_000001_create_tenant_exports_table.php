<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index()->nullable(); // null = full-app export (non-saas)
            $table->string('status')->default('pending');     // pending | processing | completed | failed
            $table->string('disk')->nullable();               // storage disk used
            $table->string('path')->nullable();               // path in the disk
            $table->unsignedBigInteger('size')->nullable();   // bytes
            $table->string('checksum')->nullable();           // md5 of the zip
            $table->text('error')->nullable();                // error message if failed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_exports');
    }
};
