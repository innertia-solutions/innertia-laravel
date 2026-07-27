<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryPresence;

uses(RefreshDatabase::class);

it('records a heartbeat and upserts presence by session', function () {
    $this->postJson('/platform/telemetry/presence', ['view' => '/dashboard'])
        ->assertStatus(204);

    $this->postJson('/platform/telemetry/presence', ['view' => '/orders'])
        ->assertStatus(204);

    // Un solo registro por session (mismo session_id resuelto), vista actualizada.
    expect(TelemetryPresence::count())->toBe(1)
        ->and(TelemetryPresence::first()->view)->toBe('/orders');
});
