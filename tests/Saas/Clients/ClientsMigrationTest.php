<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Saas\Clients\ClientsFeature;

beforeEach(fn () => bootClientsMode($this->app));

it('creates clients and adds client columns to tenants', function () {
    expect(Schema::hasTable('clients'))->toBeTrue()
        ->and(Schema::hasColumns('clients', [
            'id', 'key', 'name', 'legal_name', 'tax_id', 'business_activity', 'address', 'billing_email',
            'invoicing_mode', 'payment_terms_days', 'grace_days', 'late_fee_percent', 'status', 'notes',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('tenants', ['client_id', 'environment', 'billing_profile']))->toBeTrue();
});

it('backfills one client per existing tenant', function () {
    $tenant = DB::table('tenants')->where('key', 'rancagua')->first();
    $client = DB::table('clients')->where('id', $tenant->client_id)->first();

    expect($client)->not->toBeNull()
        ->and($client->key)->toBe('rancagua')
        ->and($client->name)->toBe('Rancagua')
        ->and($client->status)->toBe('active')
        ->and($tenant->environment)->toBe('production');
});

it('is active only when enabled in saas or open mode', function () {
    expect(ClientsFeature::isActive())->toBeTrue();

    config()->set('innertia.mode', 'app');
    expect(ClientsFeature::isActive())->toBeFalse();

    config()->set('innertia.mode', 'open');
    config()->set('innertia.saas.clients.enabled', false);
    expect(ClientsFeature::isActive())->toBeFalse();
});
