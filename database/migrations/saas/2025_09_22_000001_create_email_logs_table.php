<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->nullable()->index();
            $table->string('to');
            $table->string('subject');
            $table->string('mailable_class');
            $table->string('status')->default('pending'); // pending, sent, failed
            $table->text('exception')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
