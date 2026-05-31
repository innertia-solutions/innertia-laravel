<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('key')->unique();
            $table->string('status')->default('active')->index(); // active | suspended
            $table->timestamps();
            $table->softDeletes();
        });

        // Self-referencing FK added in a separate statement so PostgreSQL sees the
        // primary key (unique constraint on `id`) before the constraint is created.
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
