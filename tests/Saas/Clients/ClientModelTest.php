<?php

use Innertia\Saas\Enums\InvoicingMode;
use Innertia\Saas\Enums\TenantEnvironment;
use Innertia\Saas\Models\Client;
use Innertia\Saas\Models\Tenant;

beforeEach(fn () => bootClientsMode($this->app));

it('relates clients and tenants', function () {
    $tenant = Tenant::findByKey('rancagua');

    expect($tenant->client)->toBeInstanceOf(Client::class)
        ->and($tenant->client->tenants->pluck('key')->all())->toBe(['rancagua'])
        ->and($tenant->environment)->toBe(TenantEnvironment::Production)
        ->and($tenant->isTesting())->toBeFalse()
        ->and($tenant->client->invoicing_mode)->toBe(InvoicingMode::Consolidated);
});

it('merges the space billing profile over the client billing data', function () {
    $tenant = Tenant::findByKey('rancagua');
    $tenant->client->update(['legal_name' => 'Corporación X', 'tax_id' => '76.000.000-0', 'billing_email' => 'pagos@x.cl']);
    $tenant->update(['billing_profile' => ['billing_email' => 'cesfam@x.cl']]);

    expect($tenant->fresh()->billingData())->toMatchArray([
        'legal_name'    => 'Corporación X',
        'tax_id'        => '76.000.000-0',
        'billing_email' => 'cesfam@x.cl',
    ]);
});
