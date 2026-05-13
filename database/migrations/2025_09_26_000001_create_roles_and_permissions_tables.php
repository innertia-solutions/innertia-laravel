<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Innertia custom permissions system.
 *
 * Replaces spatie/laravel-permission with a unified, morph-aware schema.
 *
 * Tables created:
 *   permissions      — named app permissions AND entity-level morph permissions
 *   roles            — role definitions (per-tenant in SaaS mode)
 *   role_permissions — which permissions belong to a role
 *   model_roles      — which roles are assigned to a model (user → role)
 *   model_permissions— direct permission grants to any model
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSaas = config('innertia.mode') === 'saas';

        // ── Permissions ───────────────────────────────────────────────────────
        // Named permissions (entity_type = null, entity_id = null):
        //   name = 'users.view', 'clients.manage', etc.
        //
        // Entity-level permissions (entity_type + entity_id set):
        //   name = 'access', entity_type = 'Innertia\Models\File', entity_id = <uuid>
        //   Used to grant access to a specific model instance.
        //
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');

            // null for named app permissions; set for entity-level grants
            $table->string('entity_type')->nullable()->index();
            $table->string('entity_id')->nullable()->index();

            $table->string('description')->nullable();
            $table->timestamps();

            // Compound index to speed up lookups
            $table->index(['entity_type', 'entity_id']);
        });

        // ── Roles ─────────────────────────────────────────────────────────────
        Schema::create('roles', function (Blueprint $table) use ($isSaas) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('description')->nullable();

            // In SaaS mode each tenant has its own role set.
            // null means the role belongs to the central/platform level.
            if ($isSaas) {
                $table->string('tenant_id')->nullable()->index();
            }

            $table->timestamps();
        });

        // ── Role → Permission ──────────────────────────────────────────────────
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        // ── Model → Role (user gets a role) ───────────────────────────────────
        Schema::create('model_roles', function (Blueprint $table) {
            $table->string('model_type');
            $table->string('model_id');   // stored as string to support UUID + int PKs
            $table->uuid('role_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();

            $table->primary(['model_type', 'model_id', 'role_id']);
            $table->index(['model_type', 'model_id']);
        });

        // ── Model → Permission (direct grant, bypasses roles) ─────────────────
        Schema::create('model_permissions', function (Blueprint $table) {
            $table->string('model_type');
            $table->string('model_id');
            $table->uuid('permission_id');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->primary(['model_type', 'model_id', 'permission_id']);
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_permissions');
        Schema::dropIfExists('model_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
