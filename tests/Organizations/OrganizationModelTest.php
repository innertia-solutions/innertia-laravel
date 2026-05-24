<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Platform\Contracts\OrganizationContract;

pest()->group('org-enabled');

beforeEach(function () {
    // Manually create the schema required by Organization. The saas-mode
    // migration auto-loads when the host app sets mode=saas; in the lib's
    // generic test suite (mode=app) we create it explicitly per test.
    Schema::create('tenants', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('key')->unique();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('organizations', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('name');
        $table->string('key');
        $table->boolean('active')->default(true);
        $table->timestamps();
        $table->unique(['tenant_id', 'key']);
    });
});

afterEach(function () {
    Schema::dropIfExists('organizations');
    Schema::dropIfExists('tenants');
});

it('exposes the expected schema surface', function () {
    $org = new Organization();
    expect($org->getFillable())->toEqualCanonicalizing(['tenant_id', 'name', 'key', 'active']);
    expect($org->getCasts())->toMatchArray(['active' => 'bool']);
    expect($org->getTable())->toBe('organizations');
    expect($org->getRouteKeyName())->toBe('key');
});

it('implements OrganizationContract', function () {
    expect((new ReflectionClass(Organization::class))->implementsInterface(OrganizationContract::class))
        ->toBeTrue();
});

it('persists and reads back', function () {
    $org = Organization::create([
        'tenant_id' => 1,
        'name'      => 'Acme NA',
        'key'       => 'acme-na',
    ]);

    expect($org->id)->toBeInt();
    expect($org->active)->toBeTrue();           // default
    expect($org->getTenantId())->toBe(1);
    expect(Organization::findByKey('acme-na')->is($org))->toBeTrue();
});

it('scopes byKey() to a slug', function () {
    Organization::create(['tenant_id' => 1, 'name' => 'A', 'key' => 'a']);
    Organization::create(['tenant_id' => 1, 'name' => 'B', 'key' => 'b']);

    expect(Organization::byKey('b')->first()->key)->toBe('b');
});

it('enforces (tenant_id, key) uniqueness', function () {
    Organization::create(['tenant_id' => 1, 'name' => 'A', 'key' => 'dup']);
    expect(fn () => Organization::create(['tenant_id' => 1, 'name' => 'A2', 'key' => 'dup']))
        ->toThrow(\Illuminate\Database\QueryException::class);

    // Same key on different tenant is allowed.
    Organization::create(['tenant_id' => 2, 'name' => 'A', 'key' => 'dup']);
    expect(Organization::where('key', 'dup')->count())->toBe(2);
});
