<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Innertia\Api\Models\ApiKey;
use Innertia\Api\Models\Organization;

beforeEach(function () {
    config()->set('innertia.mode', 'api');
    config()->set('innertia.api.key_prefix', 'cog_');
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Schema::create('organizations', function ($t) {
        $t->uuid('id')->primary();
        $t->uuid('parent_id')->nullable();
        $t->string('name');
        $t->string('key')->unique();
        $t->string('status')->default('active');
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('api_keys', function ($t) {
        $t->uuid('id')->primary();
        $t->uuid('organization_id');
        $t->string('name')->nullable();
        $t->string('key')->unique();
        $t->string('key_prefix', 12)->index();
        $t->string('key_hint', 8);
        $t->boolean('is_default')->default(false);
        $t->timestamp('revoked_at')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamps();
    });
});

it('generates a key with correct prefix', function () {
    $result = ApiKey::generate('org-uuid', 'Default', isDefault: true);
    expect($result['raw'])->toStartWith('cog_')
        ->and($result['attributes']['key_prefix'])->toHaveLength(12)
        ->and($result['attributes']['is_default'])->toBeTrue();
});

it('finds an active key by raw key', function () {
    $org = Organization::create(['name' => 'Test', 'key' => 'test']);
    ['raw' => $raw, 'attributes' => $attrs] = ApiKey::generate($org->id, 'Default');
    ApiKey::create(['organization_id' => $org->id, ...$attrs]);

    $found = ApiKey::findByRawKey($raw);
    expect($found)->not->toBeNull()
        ->and($found->organization_id)->toBe($org->id);
});

it('returns null for revoked key', function () {
    $org = Organization::create(['name' => 'Test', 'key' => 'test']);
    ['raw' => $raw, 'attributes' => $attrs] = ApiKey::generate($org->id, 'Default');
    $key = ApiKey::create(['organization_id' => $org->id, ...$attrs]);
    $key->revoke();

    expect(ApiKey::findByRawKey($raw))->toBeNull();
});

it('returns null for unknown prefix', function () {
    expect(ApiKey::findByRawKey('unknown_badprefix12345'))->toBeNull();
});

it('touches last_used_at', function () {
    $org = Organization::create(['name' => 'Test', 'key' => 'test']);
    ['raw' => $raw, 'attributes' => $attrs] = ApiKey::generate($org->id, 'Default');
    $key = ApiKey::create(['organization_id' => $org->id, ...$attrs]);

    expect($key->last_used_at)->toBeNull();
    $key->touchLastUsed();
    expect($key->fresh()->last_used_at)->not->toBeNull();
});
