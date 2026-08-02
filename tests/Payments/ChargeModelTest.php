<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Models\Charge;

uses(RefreshDatabase::class);

it('persiste billable y payer polimórficos + metadata', function () {
    $charge = Charge::create([
        'amount'         => 12000,
        'currency'       => 'CLP',
        'status'         => 'pending',
        'billable_type'  => 'App\\Domain\\Membership',
        'billable_id'    => 'mem-1',
        'payer_type'     => 'App\\Domain\\User',
        'payer_id'       => 'usr-1',
        'application_fee'=> 900,
        'metadata'       => ['concept' => 'Mensualidad', 'period' => '2026-08'],
    ]);

    $fresh = $charge->fresh();

    expect($fresh->billable_type)->toBe('App\\Domain\\Membership')
        ->and($fresh->billable_id)->toBe('mem-1')
        ->and($fresh->payer_id)->toBe('usr-1')
        ->and($fresh->application_fee)->toBe(900)
        ->and($fresh->metadata['period'])->toBe('2026-08')
        ->and($fresh->status)->toBe(ChargeStatus::Pending);
});

it('valida transiciones de estado y lanza en las inválidas', function () {
    $charge = Charge::create(['amount' => 1000, 'currency' => 'CLP', 'status' => 'pending']);

    $charge->transitionTo(ChargeStatus::Paid);
    expect($charge->fresh()->status)->toBe(ChargeStatus::Paid);

    expect(fn () => $charge->transitionTo(ChargeStatus::Pending))
        ->toThrow(\DomainException::class);
});

it('expone billable y payer como relaciones morphTo', function () {
    $charge = new Charge();
    expect($charge->billable())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class)
        ->and($charge->payer())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class);
});
