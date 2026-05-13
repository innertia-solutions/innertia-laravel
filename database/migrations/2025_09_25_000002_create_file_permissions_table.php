<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('file_id');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            // Can be a User model or role name string ('admin', 'manager')
            // For users: permissionable_type = User::class, permissionable_id = user uuid
            // For roles: permissionable_type = 'role', permissionable_id = 'admin'
            $table->string('permissionable_type');
            $table->string('permissionable_id');

            $table->timestamps();

            $table->unique(['file_id', 'permissionable_type', 'permissionable_id'], 'file_permissions_unique');
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_permissions');
    }
};
