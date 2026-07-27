<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryEvent;

uses(RefreshDatabase::class);

it('accepts a client error beacon and stores it as a client event', function () {
    $res = $this->postJson('/platform/telemetry/client', [
        'view'    => '/orders/new',
        'action'  => 'submit',
        'message' => 'TypeError: cannot read x',
        'context' => ['stack' => '...'],
    ]);

    $res->assertStatus(202);

    $event = TelemetryEvent::where('type', 'client')->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['message'])->toBe('TypeError: cannot read x')
        ->and($event->payload['view'])->toBe('/orders/new');
});
