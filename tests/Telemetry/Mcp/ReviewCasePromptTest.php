<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Mcp\ObservabilityServer;
use Innertia\Telemetry\Mcp\Prompts\ReviewCasePrompt;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

it('review_case prompt embeds the case context', function () {
    $case = TelemetryCase::create([
        'fingerprint' => str_repeat('c', 64), 'title' => 'Kaboom in Y.php:9',
        'exception_class' => \RuntimeException::class, 'file' => '/Y.php', 'line' => 9,
        'message_normalized' => 'kaboom', 'status' => 'open', 'times_seen' => 1,
        'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't1', 'sample_trace_ids' => ['t1'],
    ]);

    ObservabilityServer::prompt(ReviewCasePrompt::class, ['case_id' => $case->id])
        ->assertOk()
        ->assertSee('Kaboom in Y.php:9');
});
