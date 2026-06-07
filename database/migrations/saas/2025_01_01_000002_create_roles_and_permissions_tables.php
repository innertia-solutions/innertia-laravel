<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia custom RBAC + entity permissions system (SaaS — tenant-scoped).
 *
 * ── Named permissions (RBAC) ──────────────────────────────────────────────────
 *   permissions       — named permission definitions, scoped per tenant
 *   roles             — role definitions, scoped per tenant
 *   role_permissions  — role → named permission
 *   model_roles       — model (User) → role
 *   model_permissions — model (User) → named permission (direct grant)
 *
 * ── Entity-level access control ───────────────────────────────────────────────
 *   entity_permissions — grants access to a specific model instance, scoped per tenant
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('model_roles', function (Blueprint $table) {
            $table->string('model_type');
            $table->uuid('model_id'); // User|Team usan uuid (convención del kit); evita uuid = varchar en pgsql
            $table->uuid('role_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();

            $table->primary(['model_type', 'model_id', 'role_id']);
            $table->index(['model_type', 'model_id']);
        });

        Schema::create('model_permissions', function (Blueprint $table) {
            $table->string('model_type');
            $table->uuid('model_id'); // User|Team usan uuid (convención del kit); evita uuid = varchar en pgsql
            $table->uuid('permission_id');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->primary(['model_type', 'model_id', 'permission_id']);
            $table->index(['model_type', 'model_id']);
        });

        Schema::create('entity_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('grantable_type');
            $table->string('grantable_id');
            $table->string('action')->default('access');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['entity_type', 'entity_id', 'grantable_type', 'grantable_id', 'action'],
                'entity_permissions_unique'
            );
            $table->index(['entity_type', 'entity_id']);
            $table->index(['grantable_type', 'grantable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_permissions');
        Schema::dropIfExists('model_permissions');
        Schema::dropIfExists('model_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
