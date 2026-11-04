<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

pest()->group('org-enabled');
beforeEach(function () {
    config()->set('innertia.mode', 'open');
    config()->set('innertia.organizations.enabled', true);
    config()->set('innertia.organizations.tables', []);
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $this->dir = sys_get_temp_dir().'/org-install-'.uniqid();
    File::makeDirectory($this->dir);
});
afterEach(fn () => File::deleteDirectory($this->dir));

function generateOrgInstallMigration($test): object
{
    $test->artisan('innertia:organization:install', ['--path' => $test->dir, '--force' => true])->assertSuccessful();

    return require collect(File::files($test->dir))->first()->getPathname();
}

/** Schema RBAC pre-install tal como lo crean las migraciones saas/app de la lib. */
function createPreInstallRbac(bool $tenancy): void
{
    Schema::create('roles', function ($t) use ($tenancy) {
        $t->uuid('id')->primary();
        if ($tenancy) {
            $t->string('tenant_id')->nullable();
        }
        $t->string('name');
    });
    Schema::create('model_roles', function ($t) {
        $t->string('model_type');
        $t->uuid('model_id');
        $t->uuid('role_id');
        $t->primary(['model_type', 'model_id', 'role_id']);
        $t->index(['model_type', 'model_id']);
    });
    Schema::create('model_permissions', function ($t) {
        $t->string('model_type');
        $t->uuid('model_id');
        $t->uuid('permission_id');
        $t->primary(['model_type', 'model_id', 'permission_id']);
    });
    Schema::create('user_contexts', function ($t) use ($tenancy) {
        $t->id();
        $t->string('user_id');
        $t->string('context');
        if ($tenancy) {
            $t->string('tenant_id')->index();
        }
        $t->timestamps();
        $t->unique($tenancy ? ['user_id', 'context', 'tenant_id'] : ['user_id', 'context']);
    });
}

it('la migración generada rehace las claves de model_roles y user_contexts', function () {
    $this->artisan('innertia:organization:install', ['--path' => $this->dir, '--force' => true])->assertSuccessful();
    $src = File::get(collect(File::files($this->dir))->first()->getPathname());

    expect($src)->toContain("dropPrimary(['model_type', 'model_id', 'role_id'])")
        ->toContain("'model_roles_scoped_unique'")
        ->toContain("dropUnique(['user_id', 'context', 'tenant_id'])")
        ->toContain("'user_contexts_scoped_unique'");
});

it('aplicada sobre el schema saas permite el mismo rol y contexto en dos orgs (up + down)', function () {
    createPreInstallRbac(tenancy: true);
    $migration = generateOrgInstallMigration($this);

    $migration->up();
    $migration->up(); // idempotente

    $mr = collect(Schema::getIndexes('model_roles'));
    expect($mr->contains('name', 'model_roles_scoped_unique'))->toBeTrue();
    expect($mr->contains(fn ($i) => $i['primary'] ?? false))->toBeFalse();
    expect(collect(Schema::getIndexes('user_contexts'))->contains('name', 'user_contexts_scoped_unique'))->toBeTrue();

    $row = ['model_type' => 'user', 'model_id' => 'u1', 'role_id' => 'r1'];
    DB::table('model_roles')->insert($row + ['organization_id' => 10]);
    DB::table('model_roles')->insert($row + ['organization_id' => 20]);
    $ctx = ['user_id' => 'u1', 'context' => 'backoffice', 'tenant_id' => '1'];
    DB::table('user_contexts')->insert($ctx + ['organization_id' => 10]);
    DB::table('user_contexts')->insert($ctx + ['organization_id' => 20]);

    expect(fn () => DB::table('model_roles')->insert($row + ['organization_id' => 10]))
        ->toThrow(\Illuminate\Database\QueryException::class);

    DB::table('model_roles')->delete();
    DB::table('user_contexts')->delete();
    $migration->down();

    expect(Schema::hasColumn('model_roles', 'organization_id'))->toBeFalse();
    expect(collect(Schema::getIndexes('model_roles'))->contains(fn ($i) => $i['primary'] ?? false))->toBeTrue();
    expect(collect(Schema::getIndexes('user_contexts'))->contains('name', 'user_contexts_user_id_context_tenant_id_unique'))->toBeTrue();
});

it('aplicada sobre el schema app (sin tenant_id) rehace la unicidad de user_contexts', function () {
    config()->set('innertia.mode', 'app');
    createPreInstallRbac(tenancy: false);
    $migration = generateOrgInstallMigration($this);

    $migration->up();

    $ctx = ['user_id' => 'u1', 'context' => 'backoffice'];
    DB::table('user_contexts')->insert($ctx + ['organization_id' => 10]);
    DB::table('user_contexts')->insert($ctx + ['organization_id' => 20]);
    expect(DB::table('user_contexts')->count())->toBe(2);

    DB::table('user_contexts')->delete();
    $migration->down();
    expect(collect(Schema::getIndexes('user_contexts'))->contains('name', 'user_contexts_user_id_context_unique'))->toBeTrue();
});
