<?php

use Innertia\Exceptions\ConflictException;
use Innertia\Saas\Enums\InvoicingMode;
use Innertia\Saas\Enums\TenantEnvironment;
use Innertia\Saas\Models\Tenant;
use Innertia\Saas\UseCases\CreateClient;
use Innertia\Saas\UseCases\SetTenantEnvironment;
use Innertia\Saas\UseCases\UpdateClient;

beforeEach(fn () => bootClientsMode($this->app));

it('creates a client with a slug key and billing data', function () {
    $client = (new CreateClient(name: 'Corporación Machalí', attributes: [
        'tax_id' => '70.000.000-1', 'invoicing_mode' => 'per_space', 'key' => 'ignored',
    ]))->execute();

    expect($client->key)->toBe('corporacion-machali')
        ->and($client->tax_id)->toBe('70.000.000-1')
        ->and($client->invoicing_mode)->toBe(InvoicingMode::PerSpace);
});

it('generates a unique key when the slug is taken', function () {
    (new CreateClient(name: 'Rancagua'))->execute();   // 'rancagua' already exists from the backfill

    expect(CreateClient::uniqueKey('Rancagua'))->toBe('rancagua-3');
});

it('rejects an explicit duplicated key', function () {
    expect(fn () => (new CreateClient(name: 'Otra', key: 'rancagua'))->execute())
        ->toThrow(ConflictException::class);
});

it('updates editable fields but never the key', function () {
    $client = (new CreateClient(name: 'Cliente X'))->execute();
    (new UpdateClient($client, ['name' => 'Cliente Y', 'key' => 'hack', 'status' => 'inactive']))->execute();

    expect($client->fresh()->only(['name', 'key', 'status']))
        ->toBe(['name' => 'Cliente Y', 'key' => 'cliente-x', 'status' => 'inactive']);
});

it('switches a space environment', function () {
    (new SetTenantEnvironment('rancagua', TenantEnvironment::Testing))->execute();

    expect(Tenant::findByKey('rancagua')->environment)->toBe(TenantEnvironment::Testing);
});

it('drops nulls for non-nullable columns in CreateClient and UpdateClient', function () {
    $client = (new \Innertia\Saas\UseCases\CreateClient(name: 'Defensivo', attributes: [
        'invoicing_mode' => null, 'payment_terms_days' => null, 'grace_days' => null, 'late_fee_percent' => null,
    ]))->execute();

    (new \Innertia\Saas\UseCases\UpdateClient($client, ['grace_days' => null, 'legal_name' => 'X SpA']))->execute();

    $fresh = $client->fresh();
    expect($fresh->invoicing_mode)->not->toBeNull()
        ->and($fresh->grace_days)->not->toBeNull()
        ->and($fresh->legal_name)->toBe('X SpA');
});
