<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia — Tablas de usuarios y autenticación.
 *
 *   users                — Cuentas de usuario (UUID, 2FA, soft deletes)
 *   password_reset_tokens — Tokens de recuperación de contraseña
 *   user_sessions        — Sesiones JWT activas por dispositivo
 *   user_otps            — Códigos OTP de un solo uso (login, verificación)
 *   user_tokens          — Tokens de acción (reset password, email verify, etc.)
 *   user_apps            — Acceso del usuario a cada app del sistema
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('seen_at')->nullable();

            $table->boolean('force_password_change')->default(false);

            // 2FA (TOTP via pragmarx/google2fa)
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('token_hash')->unique();
            $table->string('device_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('browser')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_otps', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('code', 6);
            $table->string('action');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'action', 'active']);
        });

        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('token', 64)->unique();
            $table->string('action');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'action', 'active']);
        });

        Schema::create('user_apps', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('app');
            $table->timestamps();

            $table->unique(['user_id', 'app']);
            $table->index('user_id');
            $table->index('app');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_apps');
        Schema::dropIfExists('user_tokens');
        Schema::dropIfExists('user_otps');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
