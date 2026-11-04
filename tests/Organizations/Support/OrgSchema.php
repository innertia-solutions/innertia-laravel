<?php

namespace Innertia\Tests\Organizations\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Schema sqlite equivalente al de un producto saas/open DESPUÉS de correr
 * innertia:organization:install (con las unicidades multi-org corregidas).
 */
final class OrgSchema
{
    public static function create(): void
    {
        Schema::create('tenants', function ($t) {
            $t->bigIncrements('id');
            $t->string('key')->unique();
            $t->string('name');
            $t->string('status')->default('active');
            $t->timestamp('trial_ends_at')->nullable();
            $t->json('configs')->nullable();
            $t->json('data')->nullable();
            $t->timestamps();
        });
        Schema::create('organizations', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id');
            $t->string('name');
            $t->string('key');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'key']);
        });
        Schema::create('test_users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('email')->nullable();
            $t->boolean('is_platform_admin')->default(false);
        });
        Schema::create('user_contexts', function ($t) {
            $t->id();
            $t->string('user_id');
            $t->string('context');
            $t->string('tenant_id')->index();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'context', 'tenant_id', 'organization_id'], 'user_contexts_scoped_unique');
        });
        Schema::create('permissions', function ($t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->nullable();
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function ($t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
        });
        Schema::create('role_permissions', function ($t) {
            $t->uuid('role_id');
            $t->uuid('permission_id');
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('model_roles', function ($t) {
            $t->string('model_type');
            $t->uuid('model_id');
            $t->uuid('role_id');
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unique(['model_type', 'model_id', 'role_id', 'organization_id'], 'model_roles_scoped_unique');
        });
        Schema::create('model_permissions', function ($t) {
            $t->string('model_type');
            $t->uuid('model_id');
            $t->uuid('permission_id');
            $t->unsignedBigInteger('organization_id')->nullable();
        });
        Schema::create('entity_history', function ($t) {
            $t->bigIncrements('id');
            $t->string('entity_type');
            $t->string('entity_id');
            $t->string('action');
            $t->json('changes')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('user_id')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
    }
}
