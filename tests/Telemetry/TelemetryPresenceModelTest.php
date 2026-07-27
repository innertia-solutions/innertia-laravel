<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryPresence;

uses(RefreshDatabase::class);

it('upserts presence by session and updates last_seen', function () {
    TelemetryPresence::create([
        'session_id'   => 'sess-1',
        'user_id'      => 7,
        'tenant'       => 'acme',
        'view'         => '/dashboard',
        'last_seen_at' => now(),
    ]);

    expect(TelemetryPresence::where('session_id', 'sess-1')->first())
        ->view->toBe('/dashboard');
});
