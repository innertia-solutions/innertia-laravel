<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // string: los productos usan users.id bigint o uuid.
            $table->string('user_id')->index();
            $table->string('name', 120);
            $table->string('credential_id', 512)->unique();
            // CredentialRecord serializado por web-auth (clave pública, contador, transports, flags).
            $table->json('credential');
            $table->string('aaguid', 36)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
