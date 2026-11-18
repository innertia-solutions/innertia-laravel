<?php

use Innertia\Auth\Models\UserContext;
use Innertia\Exceptions\NotFoundException;
use Innertia\Saas\Enums\TenantEnvironment;
use Innertia\Saas\Models\Client;
use Innertia\Saas\UseCases\CreateClient;
use Innertia\Saas\UseCases\CreateTenant;

beforeEach(fn () => bootClientsMode($this->app));

it('creates an implicit client when none is given', function () {
    $tenant = (new CreateTenant(key: 'machali', name: 'Machalí', status: 'active'))->execute();

    expect($tenant->client->name)->toBe('Machalí')
        ->and($tenant->client->key)->toBe('machali')
        ->and($tenant->environment)->toBe(TenantEnvironment::Production);
});

it('attaches the tenant to an existing client with the given environment', function () {
    $client = (new CreateClient(name: 'Corporación'))->execute();

    $tenant = (new CreateTenant(
        key: 'machali-pruebas', name: 'Machalí (pruebas)', status: 'active',
        clientId: $client->id, environment: TenantEnvironment::Testing,
    ))->execute();

    expect($tenant->client_id)->toBe($client->id)
        ->and($tenant->isTesting())->toBeTrue()
        ->and(Client::count())->toBe(2);   // rancagua (backfill) + Corporación
});

it('fails when the client does not exist', function () {
    expect(fn () => (new CreateTenant(key: 'x', name: 'X', clientId: '00000000-0000-0000-0000-000000000000'))->execute())
        ->toThrow(NotFoundException::class);
});

it('self-serve open tenants also get a client', function () {
    $result = (new \Innertia\Saas\UseCases\CreateOpenTenant(name: 'Gym Norte', ownerUserId: '11111111-1111-1111-1111-111111111111'))->execute();

    expect($result['tenant']->client)->not->toBeNull()
        ->and(UserContext::where('tenant_id', (string) $result['tenant']->getKey())->exists())->toBeTrue();
});

it('does not touch client columns when the feature is off', function () {
    config()->set('innertia.saas.clients.enabled', false);
    $tenant = (new CreateTenant(key: 'sinfeature', name: 'Sin feature', status: 'active'))->execute();

    expect($tenant->client_id)->toBeNull()
        ->and(Client::where('key', 'sinfeature')->exists())->toBeFalse();
});
