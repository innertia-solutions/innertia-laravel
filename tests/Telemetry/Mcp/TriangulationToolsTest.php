<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Mcp\ObservabilityServer;
use Innertia\Telemetry\Mcp\Tools\FindCaseByTraceTool;
use Innertia\Telemetry\Mcp\Tools\SearchTracesBySessionTool;
use Innertia\Telemetry\Mcp\Tools\SearchTracesByUserTool;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Flujo: t1 (GET home, ok) y t2 (POST save, 500 con excepción), misma sesión y usuario.
    TelemetryTrace::create(['trace_id' => 't1', 'user_id' => 'u1', 'session_id' => 's1', 'method' => 'GET', 'route' => 'home', 'status' => 200, 'occurred_at' => now()->subMinutes(2)]);
    TelemetryTrace::create(['trace_id' => 't2', 'user_id' => 'u1', 'session_id' => 's1', 'method' => 'POST', 'route' => 'save', 'status' => 500, 'occurred_at' => now()->subMinute()]);
    TelemetryTrace::create(['trace_id' => 't3', 'user_id' => 'otro', 'session_id' => 's9', 'method' => 'GET', 'route' => 'perfil', 'status' => 200, 'occurred_at' => now()]);

    TelemetryEvent::create(['app' => 'a', 'session_id' => 's1', 'trace_id' => 't2', 'type' => 'exception', 'occurred_at' => now()->subMinute(), 'payload' => ['class' => 'RuntimeException', 'message' => 'boom'], 'context' => []]);

    TelemetryCase::create([
        'fingerprint' => str_repeat('a', 64), 'title' => 'Boom en save',
        'exception_class' => RuntimeException::class, 'file' => '/X.php', 'line' => 1,
        'message_normalized' => 'boom', 'status' => 'open', 'times_seen' => 2,
        'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't2', 'sample_trace_ids' => ['t2', 't1'],
    ]);
});

it('find_case_by_trace encuentra el caso por last_trace_id', function () {
    ObservabilityServer::tool(FindCaseByTraceTool::class, ['trace_id' => 't2'])
        ->assertOk()
        ->assertSee('Boom en save');
});

it('find_case_by_trace encuentra el caso cuando el trace está en sample_trace_ids', function () {
    ObservabilityServer::tool(FindCaseByTraceTool::class, ['trace_id' => 't1'])
        ->assertOk()
        ->assertSee('Boom en save');
});

it('find_case_by_trace no devuelve casos para un trace desconocido', function () {
    ObservabilityServer::tool(FindCaseByTraceTool::class, ['trace_id' => 'nope'])
        ->assertOk()
        ->assertSee('"cases":[]');
});

it('search_traces_by_user devuelve los traces del usuario', function () {
    ObservabilityServer::tool(SearchTracesByUserTool::class, ['user_id' => 'u1'])
        ->assertOk()
        ->assertSee('home')
        ->assertSee('save')
        ->assertDontSee('perfil');
});

it('search_traces_by_user con only_errors filtra a los que tuvieron error', function () {
    ObservabilityServer::tool(SearchTracesByUserTool::class, ['user_id' => 'u1', 'only_errors' => true])
        ->assertOk()
        ->assertSee('save')
        ->assertDontSee('home');
});

it('search_traces_by_session devuelve el flujo de la sesión', function () {
    ObservabilityServer::tool(SearchTracesBySessionTool::class, ['session_id' => 's1'])
        ->assertOk()
        ->assertSee('home')
        ->assertSee('save')
        ->assertDontSee('perfil');
});
