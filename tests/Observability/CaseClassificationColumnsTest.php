<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryCase;
uses(RefreshDatabase::class);
it('persiste origin/category/tags', function () {
    $c = TelemetryCase::create([
        'fingerprint' => 'cls1', 'title' => 'B', 'exception_class' => 'X', 'file' => '/a', 'line' => 1,
        'message_normalized' => 'b', 'status' => 'open', 'times_seen' => 1,
        'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't',
        'origin' => 'queue', 'category' => 'database', 'tags' => ['gym' => 'acme'],
    ]);
    $f = $c->fresh();
    expect($f->origin)->toBe('queue');
    expect($f->category)->toBe('database');
    expect($f->tags['gym'])->toBe('acme');
});
