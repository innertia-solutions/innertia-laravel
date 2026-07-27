<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Mcp\ObservabilityServer;
use Innertia\Telemetry\Mcp\Tools\GetCaseTool;
use Innertia\Telemetry\Mcp\Tools\ListCasesTool;
use Innertia\Telemetry\Mcp\Tools\ServerHealthTool;
use Innertia\Telemetry\Mcp\Tools\WhoIsOnlineTool;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;

uses(RefreshDatabase::class);

function makeCase(string $fp, string $status = 'open'): TelemetryCase
{
    return TelemetryCase::create([
        'fingerprint' => $fp, 'title' => 'Boom in X.php:1',
        'exception_class' => \RuntimeException::class, 'file' => '/X.php', 'line' => 1,
        'message_normalized' => 'boom', 'status' => $status, 'times_seen' => 2,
        'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't1', 'sample_trace_ids' => ['t1'],
    ]);
}

it('list_cases returns open cases as text', function () {
    makeCase(str_repeat('b', 64));

    ObservabilityServer::tool(ListCasesTool::class, ['status' => 'open'])
        ->assertOk()
        ->assertSee('Boom in X.php:1');
});

it('get_case returns the case with its trace and events', function () {
    makeCase(str_repeat('c', 64));
    TelemetryTrace::create(['trace_id' => 't1', 'method' => 'GET', 'route' => 'home', 'occurred_at' => now()]);
    TelemetryEvent::create([
        'app' => 'a', 'session_id' => 's', 'trace_id' => 't1', 'type' => 'log',
        'occurred_at' => now(), 'payload' => ['message' => 'hello-log'], 'context' => [],
    ]);

    $id = TelemetryCase::first()->id;

    ObservabilityServer::tool(GetCaseTool::class, ['case_id' => $id])
        ->assertOk()
        ->assertSee('hello-log');
});

it('server_health returns db status', function () {
    ObservabilityServer::tool(ServerHealthTool::class, [])
        ->assertOk()
        ->assertSee('"up"');
});

it('who_is_online returns an ok response with no presence', function () {
    ObservabilityServer::tool(WhoIsOnlineTool::class, [])->assertOk();
});
