<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia custom RBAC + entity permissions system.
 *
 * Two separate concerns:
 *
 * ── Named permissions (RBAC) ──────────────────────────────────────────────────
 *   permissions       — named permission definitions ('users.view', etc.)
 *   roles             — role definitions, per-tenant in SaaS mode
 *   role_permissions  — role → named permission
 *   model_roles       — model (User) → role
 *   model_permissions — model (User) → named permission (direct grant, bypasses roles)
 *
 * ── Entity-level access control ───────────────────────────────────────────────
 *   entity_permissions — grants access to a specific model instance.
 *                        grantable can be a User, Role, or another entity.
 *
 *   Examples:
 *     User directly → File          (user can access this specific file)
 *     Role          → File          (all users of this role can access this file)
 *     Entity        → Entity        (owning entity cascades access to nested entity)
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSaas = config('innertia.mode') === 'saas';

        // ── Named permissions ─────────────────────────────────────────────────
        Schema::create('permissions', function (Blueprint $table) use ($isSaas) {
            $table->uuid('id')->primary();

            if ($isSaas) {
                $table->string('tenant_id')->nullable()->index();
            }

            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // ── Roles ─────────────────────────────────────────────────────────────
        Schema::create('roles', function (Blueprint $table) use ($isSaas) {
            $table->uuid('id')->primary();

            if ($isSaas) {
                $table->string('tenant_id')->nullable()->index();
            }

            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // ── Role → Named permission ───────────────────────────────────────────
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        // ── Model → Role ──────────────────────────────────────────────────────
        Schema::create('model_roles', function (Blueprint $table) {
            $table->string('model_type');
            $table->string('model_id');   // string to support UUID + int PKs
            $table->uuid('role_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();

            $table->primary(['model_type', 'model_id', 'role_id']);
            $table->index(['model_type', 'model_id']);
        });

        // ── Model → Named permission (direct grant, bypasses roles) ───────────
        Schema::create('model_permissions', function (Blueprint $table) {
            $table->string('model_type');
            $table->string('model_id');
            $table->uuid('permission_id');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->primary(['model_type', 'model_id', 'permission_id']);
            $table->index(['model_type', 'model_id']);
        });

        // ── Entity-level access control ───────────────────────────────────────
        //
        // Grants access to a specific model instance (entity_type + entity_id)
        // to any other model (grantable_type + grantable_id).
        //
        //   grantable = User    → direct user-level access
        //   grantable = Role    → role-level access (all users of that role)
        //   grantable = Entity  → entity-cascade (owning entity grants access)
        //
        // action defaults to 'access'. Use 'edit', 'delete', etc. for granularity.
        //
        Schema::create('entity_permissions', function (Blueprint $table) use ($isSaas) {
            $table->uuid('id')->primary();

            if ($isSaas) {
                $table->string('tenant_id')->nullable()->index();
            }

            // What is being accessed
            $table->string('entity_type');
            $table->string('entity_id');

            // Who gets access (User, Role, or another entity)
            $table->string('grantable_type');
            $table->string('grantable_id');

            // What action is granted
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
