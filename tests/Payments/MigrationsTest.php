<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('crea las tablas charges y payments con columnas genéricas', function () {
    expect(Schema::hasTable('charges'))->toBeTrue()
        ->and(Schema::hasTable('payments'))->toBeTrue();

    expect(Schema::hasColumns('charges', [
        'id', 'tenant_id', 'payer_type', 'payer_id',
        'billable_type', 'billable_id', 'amount', 'currency',
        'status', 'application_fee', 'metadata', 'due_at', 'paid_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('payments', [
        'id', 'tenant_id', 'charge_id', 'provider', 'confirmed_via',
        'amount', 'external_reference', 'collection_mode', 'commission_rate',
        'link', 'link_expires_at', 'declared_at', 'paid_at',
    ]))->toBeTrue();
});
