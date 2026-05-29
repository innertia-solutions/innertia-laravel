<?php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Innertia\Api\Events\ApiKeyCreated;
use Innertia\Api\Events\OrganizationCreated;
use Innertia\Api\Models\ApiKey;
use Innertia\Api\Models\Organization;
use Innertia\Api\UseCases\RegisterOrganization;

beforeEach(function () {
    config()->set('innertia.mode', 'api');
    config()->set('innertia.api.key_prefix', 'cog_');
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
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

it('registers a root organization with a default api key', function () {
    Event::fake([OrganizationCreated::class, ApiKeyCreated::class]);

    $result = (new RegisterOrganization(name: 'Goberian', key: 'goberian'))->execute();

    expect($result['organization'])->toBeInstanceOf(Organization::class)
        ->and($result['organization']->isRoot())->toBeTrue()
        ->and($result['raw_key'])->toStartWith('cog_')
        ->and($result['api_key']->is_default)->toBeTrue();

    Event::assertDispatched(OrganizationCreated::class);
    Event::assertDispatched(ApiKeyCreated::class);
});

it('creates a child organization with default api key', function () {
    Event::fake([OrganizationCreated::class, ApiKeyCreated::class]);

    $parent = (new RegisterOrganization(name: 'Consultora', key: 'consultora'))->execute()['organization'];
    $result = (new \Innertia\Api\UseCases\CreateChildOrganization(
        parent: $parent,
        name: 'Cliente A',
        key: 'cliente-a',
    ))->execute();

    expect($result['organization']->parent_id)->toBe($parent->id)
        ->and($result['raw_key'])->toStartWith('cog_')
        ->and($result['api_key']->is_default)->toBeTrue();

    expect(Event::dispatched(OrganizationCreated::class))->toHaveCount(2);
});

it('creates an additional api key via CreateApiKey', function () {
    Event::fake([ApiKeyCreated::class]);

    $parent = (new RegisterOrganization(name: 'Goberian', key: 'goberian'))->execute()['organization'];
    $result = (new \Innertia\Api\UseCases\CreateApiKey(
        organization: $parent,
        name: 'Production Key',
    ))->execute();

    expect($result['raw_key'])->toStartWith('cog_')
        ->and($result['api_key']->is_default)->toBeFalse()
        ->and($result['api_key']->name)->toBe('Production Key');

    Event::assertDispatched(ApiKeyCreated::class);
});

it('revokes an api key via RevokeApiKey', function () {
    Event::fake([\Innertia\Api\Events\ApiKeyRevoked::class]);

    ['organization' => $org, 'api_key' => $key] = (new RegisterOrganization(name: 'Goberian', key: 'goberian'))->execute();
    (new \Innertia\Api\UseCases\RevokeApiKey($key))->execute();

    expect($key->fresh()->revoked_at)->not->toBeNull();
    Event::assertDispatched(\Innertia\Api\Events\ApiKeyRevoked::class);
});
